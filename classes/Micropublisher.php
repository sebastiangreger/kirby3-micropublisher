<?php

namespace sgkirby\Micropublisher;

use Exception;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Http\Remote;
use Kirby\Http\Response;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\V;

class Micropublisher
{
    public static function endpoint()
    {
        // access token from request is either in header or in form parameter
        if (empty($accesstoken = kirby()->request()->header('Authorization'))) {
            $body = kirby()->request()->body()->toArray();
            $accesstoken = 'Bearer ' . ($body['access_token'] ?? '');
        }
        if (strlen($accesstoken) < 10) {
            return new Response('{"error":"unauthorized","error_description":"No access token was provided in the request"}', 'application/json', 401);
        }

        // obtain token
        $response = new Remote(option('sgkirby.micropublisher.auth.token-endpoint'), [
            'body' => 1,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => $accesstoken,
            ],
        ]);

        // try to decode JSON, on error treat as form-encoded instead
        $token = json_decode($response->content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            parse_str($response->content, $token);
        }

        // get rid of the access token for security
        unset($accesstoken);

        // quit unless request is valid and authorised
        if (empty($response)) {
            return new Response('{"error":"unauthorized","error_description":"No access token was provided in the request"}', 'application/json', 401);
        } elseif (rtrim($token['me'], '/') != rtrim(kirby()->urls()->base(), '/')) {
            return new Response('{"error":"forbidden","error_description":"Not authorized for this site"}', 'application/json', 403);
        }

        // GET request: meta information
        if (kirby()->request()->is('GET')) {
            return static::returnMeta();
        }

        // POST request: create post or add media
        if (kirby()->request()->is('POST')) {
            // verify that token scope is sufficient
            if (strpos($token['scope'], 'create') === false && $token['scope'] != 'post') {
                return new Response('{"error":"insufficient_scope","error_description":"Scope of token does not allow creating content"}', 'application/json', 401);
            }

            // media endpoint: multipart/form-data request with one part named file
            if ($upload = kirby()->request()->files()->get('file')) {
                return static::createMedia($upload);
            }

            // micropub endpoint
            else {
                return static::createPost();
            }
        }
    }

    /*
     * Deals with the submission of media content (i.e. endpoint serves as media endpoint)
     */
    public static function createMedia($upload)
    {
        static::log('Micropub request to media endpoint', null, false);

        // save the file to a random subfolder in the media folder
        static::log('Upload data', print_r($upload, true));
        try {
            $name = F::safeName($upload['name']);
            $tmp_name = $upload['tmp_name'];
            $rnd = substr(sha1(rand()), 0, 8);
            $dir = kirby()->root() . '/media/micropub-uploads/' . $rnd . '/';
            if (! is_dir($dir)) {
                mkdir($dir, 0755);
            }
            move_uploaded_file($tmp_name, $dir . $name);
        } catch (Exception $e) {
            return new Response('{"error":"internal_error","error_description":"' . $e->getMessage() . '"}', 'application/json', 500);
        }

        // Set headers, return location
        header('HTTP/1.1 201 Created');
        header('Location: ' . kirby()->urls()->media() . '/micropub-uploads/' . $rnd . '/' . $name);
        exit;
    }

    /*
     * Log file writer
     */
    public static function log($title, $body, $append = true)
    {
        // write raw data to log file in micropublisher debug mode
        // TODO: Dir::make(kirby()->root('site') . '/logs/micropublisher');
        F::write(
            kirby()->root('site') . '/logs/micropublisher/micropub.log',
            '>>> ' . date('Y-m-d H:i:s') . ' '
            . $title . PHP_EOL . PHP_EOL
            . (!empty($body) ? $body . PHP_EOL . PHP_EOL : ''),
            $append
        );
    }

    /*
     * Deals with the submission of non-media content
     */
    public static function createPost()
    {
        // write log file from scratch
        static::log('Micropub request to content endpoint', null, false);

        // retrieve the payload as a neatly formatted data array
        $data = static::payload();

        // derive post type from data and retrieve variables for processing
        $posttypedata = static::applicablePosttype($data);
        if (is_array($posttypedata)) {
            extract(static::applicablePosttype($data));
        } else {
            return $posttypedata;
        }
        if (! $parent->exists()) {
            return new Response('{"error":"error","error_description":"Configured parent page does not exist"}', 'application/json', 500);
        }

        // use the post type's fields rules to translate Micropub properties in content fields
        $content = static::renderPropertiesToFields($renderingrules, $data);

        // design a unique slug for the content
        $slug = static::uniqueSlug($slugrules, $content, $parent, $data);

        // create new page
        kirby()->impersonate('kirby');
        try {
            $newpost = $parent->createChild([
                'slug'     	=> $slug,
                'template' 	=> $template,
                'draft' 	=> ($status == 'listed' || $status == 'unlisted') ? false : true,
            ]);
        } catch (Exception $e) {
            return new Response('{"error":"error","error_description":"Post could not be created: ' . $e->getMessage() . '"}', 'application/json', 500);
        }

        // store content in the newly created page
        try {
            $newpost->update($content, $targetlang);
        } catch (Exception $e) {
            return new Response('{"error":"error","error_description":"Content could not be saved: ' . $e->getMessage() . '"}', 'application/json', 500);
        }

        // new pages always created as unlisted, hence need to publish unless draft is desired
        if ($status === 'listed') {
            $newpost->changeStatus('listed');
        } elseif ($status === 'unlisted') {
            $newpost->changeStatus('unlisted');
        }

        // remove temporary uploads older than a day
        static::cleanupFiles();

        // process any attachments that may have come with the same request
        $attachments = static::processAttachments($posttype, $data);

        // attach media files to the new page
        foreach ($attachments as $attachment) {
            try {
                $file = $newpost->createFile([
                    'source'   => $attachment[1],
                    'filename' => $attachment[0],
                    'template' => $attachment[2],
                    'content' => [
                        'date' => date('Y-m-d h:m'),
                        'alt' => $attachment[4],
                    ]
                ]);
                // if desired, set this image as cover
                $coverfieldname = (string)$posttype['files'][$attachment[3]][2] ?? null;
                if ($attachment[3] == 'photo' && !empty($coverset) && !empty($coverfieldname)) {
                    $newpost->update([
                        $coverfieldname	=> $attachment[0],
                    ]);
                    // ensure this is only executed for the first photo
                    $coverset = true;
                }
            } catch (Exception $e) {
                return new Response('{"error":"invalid_request","error_description":"' . $e->getMessage() . '"}', 'application/json', 400);
            }
        }

        // Set headers, return location
        header('HTTP/1.1 201 Created');
        if ($status == 'listed' || $status == 'unlisted') {
            header('Location: ' . $newpost->url());
        } else {
            header('Location: ' . $newpost->panelUrl());
        }
        exit;
    }

    /*
     * Returns a JSON response with meta information, commonly polled by Micropub clients
     */
    public static function returnMeta()
    {
        // creating the config array
        $posttypes = [];
        foreach (static::getPosttypeConfig() as $n => $v) {
            // TODO: use array name if no name specified
            $posttypes[] = [ 'type' => $n, 'name' => $v['name'] ];
        }
        $config = [
            'media-endpoint' => kirby()->urls()->base() . '/' . option('sgkirby.micropublisher.endpoint', 'micropub'),
            'syndicate-to' => option('sgkirby.micropublisher.syndicate-to'),
            'post-types' => $posttypes,
            'categories' => page(option('sgkirby.micropublisher.categorylist.parent'))->children()->pluck(option('sgkirby.micropublisher.categorylist.taxonomy'), ',', true),
        ];

        // q=syndicate-to: tell client where I can syndicate to
        if (get('q') == 'syndicate-to') {
            return new Response(json_encode([ 'syndicate-to' => $config['syndicate-to'] ]), 'application/json', 200);
        }
        // q=config: give client essential information
        elseif (get('q') == 'config') {
            return new Response(json_encode($config), 'application/json', 200);
        }
        // other GET requests: fail gracefully
        else {
            return new Response('<p>This is the Micropub endpoint for <a href="' . kirby()->urls()->base() . '">' . kirby()->urls()->base() . '</a></p>', 'text/html', 200);
        }
    }

    /*
     * Translates the Microformat properties from the Micropub request
     * into a associative array for ease-of-use in further processing
     */
    public static function payload()
    {
        // the payload is in the body
        $data = kirby()->request()->body()->toArray();
        static::log('Raw microformat payload', print_r($data, true));

        // detect JSON syntax and pre-process data variables accordingly (incl. HTML content)
        if (isset($data['properties'])) {
            $type = str_replace('h-', '', $data['type']);
            $data = $data['properties'];
            $data['h'] = $type;
            foreach ($data as $n => $v) {
                if (is_array($v) && sizeof($v) == 1) {
                    if (is_array($v[0]) && array_key_exists('html', $v[0])) {
                        // translate html content into markdown
                        if (option('markdown.extra', false) === false) {
                            $converter = new \Markdownify\Converter();
                        } else {
                            $converter = new \Markdownify\ConverterExtra();
                        }
                        $data[$n] = $converter->parseString($v[0]['html']);
                    } else {
                        $data[$n] = $v[0];
                    }
                }
            }
        }

        // the access token from the request must never be stored
        unset($data['access_token']);

        // deal with deprecated legacy field names
        $deprecated = [
            'slug'         => 'mp-slug',
            'syndicate-to' => 'mp-syndicate-to'
        ];
        foreach ($deprecated as $old => $new) {
            if (isset($data[$old])) {
                $data[$new] = $data[$old];
                unset($data[$old]);
            }
        }

        // log and return
        static::log('Microformat payload formatted into array $data', print_r($data, true));
        return $data;
    }

    /*
     * Determines the applicable post type and returns the variables
     * required for further processing
     */
    public static function getPosttypeConfig()
    {
        return option('sgkirby.micropublisher.posttypes', [
            'builtindefault' => [
                'name'	=> 'Default',
                'template'	=> option('sgkirby.micropublisher.default.template', 'note'),
                'parent'	=> option('sgkirby.micropublisher.default.parent', 'notes'),
                'render' 	=> option('sgkirby.micropublisher.default.render', [
                    'name'		=> [ 'title', 'No title' ],
                    /*
                    'content'	=> [ 'text', '', function( $value, $fieldname, $default ) { return [ 'title' => 'Modified', $fieldname => $value ]; } ],
                    */
                    'content'	=> [ 'text', '' ],
                    'category'	=> [ 'tags', null ],
                    'published'	=> [ 'date', strftime( '%F %T' ), 'datetime' ],
                    /*
                    'checkin'	=> [ 'checkin', null, 'yaml' ],
                    */
                ]),
                /*
                'files'		=> [
                    'photo'		=> [ 'image', true, 'cover' ],
                ],
                */
                // TODO: syndication (mp-syndicate-to)
            ],
        ]);
    }

    /*
     * Determines the applicable post type and returns the variables
     * required for further processing
     */
    public static function applicablePosttype($data)
    {
        // find the applicable post type
        foreach (static::getPosttypeConfig() as $posttypeid => $posttype) {
            $match = null;
            $nomatch = null;

            // treat build-in fallback as instant match
            if ($posttypeid === 'builtindefault') {
                $match = true;
            }

            // no rules means catchall
            if (empty($posttype['identify']) || !is_array($posttype['identify'])) {
                $match = true;
            }

            // a field that is unique to this type is an instant match (e.g. "like-of" for likes)
            elseif (isset($posttype['identify']['unique']) && array_key_exists($posttype['identify']['unique'], $data)) {
                $match = true;
            }

            // otherwise need a precise match of has/hasnot fields to determine type
            else {
                if (isset($posttype['identify']['has'])) {
                    foreach ($posttype['identify']['has'] as $has) {
                        if (array_key_exists($has, $data)) {
                            $match = true;
                        } else {
                            $nomatch = true;
                        }
                    }
                }
                if (isset($posttype['identify']['hasnot'])) {
                    foreach ($posttype['identify']['hasnot'] as $hasnot) {
                        if (! array_key_exists($hasnot, $data)) {
                            $match = true;
                        } else {
                            $nomatch = true;
                        }
                    }
                }
            }

            // end search successfully if this type has matches but no nomatches
            if ($match && ! $nomatch) {

                // rendering ruleset, at least one field has to be defined in either posttype array or default
                // TODO: could make sense to have a few more validity checks?
                if (!isset($posttype['render']) || !is_array($posttype['render']) || sizeof($posttype['render']) < 1) {
                    if (option('sgkirby.micropublisher.default.render')) {
                        $renderingrules = option('sgkirby.micropublisher.default.render');
                    } else {
                        return new Response('{"error":"error","error_description":"Matching post type lacks rendering definitions; at least one required"}', 'application/json', 500);
                    }
                } else {
                    $renderingrules = $posttype['render'];
                }

                // slug rules, if set
                $slugrules = $posttype['slug'] ?? option('sgkirby.micropublisher.default.slug', 'slug');

                // template is defined in post type setup array
                $template = $posttype['template'] ?? option('sgkirby.micropublisher.default.template', 'default');

                // determine the default status for this post type
                $statussetting = $posttype['status'] ?? option('sgkirby.micropublisher.default.status', ['listed', 'draft']);
                if (is_array($statussetting) && in_array($statussetting[0], ['listed', 'unlisted', 'draft']) && in_array($statussetting[1], ['listed', 'unlisted', 'draft'])) {
                    // if status setting is given as array, these correspond to published/draft
                    $published = $statussetting[0];
                    $draft = $statussetting[1];
                } elseif (in_array($statussetting, ['listed', 'unlisted', 'draft'])) {
                    // if status setting is a string, it is enforced no matter what
                    $published = $draft = $statussetting;
                } else {
                    return new Response('{"error":"invalid_request","error_description":"Invalid status setting for post type ' . $posttypeid . '"}', 'application/json', 400);
                }
                if (isset($data['post-status']) && $data['post-status'] == 'draft') {
                    // apply status defined for draft only, if chosen in client
                    $status = $draft;
                } else {
                    // general default is the defined status for "published"
                    $status = $published;
                }

                // for the parent page: post type specific default overrides site-wide default setting overrides plugin default
                $defaultparent = option('sgkirby.micropublisher.default.parent') ? page(option('sgkirby.micropublisher.default.parent')) : site();
                // TODO: check that page exists, otherwise fall back to site()
                $parent = page($posttype['parent']) ?? $defaultparent;

                // target language is site default language, unless set for this specific post type
                $targetlang = null;
                if (array_key_exists('language', $posttype) && kirby()->language((string)$posttype['language']) !== null) {
                    $targetlang = $posttype['language'];
                }

                // no further loops required; we have a match!
                break;
            }
        }
        if (! isset($renderingrules)) {
            return new Response('{"error":"invalid_request","error_description":"No matching post type found in setup"}', 'application/json', 400);
        }

        return [
            'posttype'       => $posttype,
            'renderingrules' => $renderingrules,
            'slugrules'      => (array)$slugrules,
            'template'       => (string)$template,
            'status'         => (string)$status,
            'parent'         => $parent,
            'targetlang'     => $targetlang,
        ];
    }

    /*
     * Reders/transposes the Microformat properties from the Micropub request
     * into an array of fields to create the new Kirby page from
     */
    public static function renderPropertiesToFields($renderingrules, $data)
    {
        // loop through all rendering rules defined in the post type array
        foreach ($renderingrules as $mfname => $field) {
            // make sure not to process any reserved Micropub properties processed elsewhere
            if (! in_array($mfname, ['mp-slug', 'mp-syndicate-to', 'files', 'photo', 'video', 'audio'])) {
                // if field is empty...
                if (! isset($data[$mfname]) || $data[$mfname] == '') {
                    // ...and default value is given: use default
                    if (! empty($field[1])) {
                        $content[$field[0]] = is_array($field[1]) ? implode(', ', $field[1]) : $field[1];
                    }
                    // ...and no default value is given: empty string
                    else {
                        $content[$field[0]] = '';
                    }
                }
                // otherwise process the submitted value...
                else {
                    // ...with special treatment...
                    if (isset($field[2])) {
                        // ...either processing anonymous function
                        if (! is_string($field[2]) && is_callable($field[2])) {
                            $value = $field[2]($data[$mfname], $field[0], $field[1]);
                            // in case an array is returned, fill the according fields with its contents
                            if (is_array($value)) {
                                foreach ($value as $k => $v) {
                                    if ($k == $field[0]) {
                                        $value = $v;
                                    } else {
                                        $content[$k] = $v;
                                    }
                                }
                            }
                        }
                        // ...or applying presets
                        else {
                            switch ($field[2]) {
                                // ...for dates
                                case 'datetime':
                                    // if the entered string validates into a date, use that
                                    if (strtotime($data[$mfname])) {
                                        $value = strftime('%F %T', strtotime($data[$mfname]));
                                    }
                                    // if the entered string is not a valid date, use the default from the fields array
                                    else {
                                        $value = $field[1];
                                    }
                                    break;
                                // ...for structured data
                                case 'yaml':
                                case 'json':
                                    if (is_array($data[$mfname])) {
                                        $value = Data::encode([ $data[$mfname] ], 'yaml');
                                    }
                                    break;
                            }
                        }
                    }
                    // ...or by using the bare values, possibly stringified from array (e.g. tags)
                    else {
                        $value = is_array($data[$mfname]) ? implode(', ', $data[$mfname]) : $data[$mfname];
                    }
                    $content[$field[0]] = $value;
                }
            }
        }

        // log and return
        static::log('Content after rendering using the render rules', print_r($content, true));
        return $content;
    }

    /*
     * Creates a slug and makes sure it is unique
     */
    public static function uniqueSlug($slugrules, $content, $parent, $data)
    {
        // slug design based on rules from post type array
        foreach ($slugrules as $v) {
            if (($v == 'slug' || $v == 'mp-slug') && isset($data['mp-slug']) && $data['mp-slug'] != '') {
                $slug = Str::slug($data['mp-slug']);
                break;
            } elseif (!empty($content[$v])) {
                if (is_array($v) && is_string($content[$v][0]) && is_int($content[$v][1])) {
                    $slug = Str::slug(Str::excerpt($content[$v][0], $content[$v][1], true, ''));
                    break;
                } elseif (is_string($v) && !empty($content[$v])) {
                    $slug = Str::slug($content[$v]);
                    break;
                }
            }
        }
        // fallback slug is unix timestamp
        if (empty($slug)) {
            $slug = time();
        }

        // avoid duplicate slugs
        $i = 1;
        $testslug = option('sgkirby.micropublisher.slugprefix') . $slug;
        while ($parent->find($testslug) || $parent->draft($testslug)) {
            $i++;
            $testslug = option('sgkirby.micropublisher.slugprefix') . $slug . '-' . $i;
        }
        if ($i > 1) {
            $slug = $slug . '-' . $i;
        }

        // log and return
        static::log('Computed and de-duplicated slug', $slug);
        return $slug;
    }

    /*
     * Adds any attachments to the page, if settings allow
     */
    public static function cleanupFiles(int $minutes = 86400)
    {
        // clean temp file storage from all files older than a day
        $tempdir = kirby()->root() . '/media/micropub-uploads/';
        foreach (Dir::dirs($tempdir) as $dir) {
            if (time() - Dir::modified($tempdir . $dir) > $minutes) {
                Dir::remove($tempdir . $dir);
            }
        }
    }

    /*
     * Adds any attachments to the page, if settings allow
     */
    public static function processAttachments($posttype, $data)
    {
        $tempdir = kirby()->root() . '/media/micropub-uploads/';

        // array collects all files to be attached
        $attachments = [];

        // loop through the three formats defined in Micropub spec
        foreach (['photo', 'audio', 'video'] as $format) {

            // use format name as file template name, if not set
            $filetemplate = $posttype['files'][$format][0] ?? $format;

            // do not process if file format is set to false in post type config
            if (isset($posttype['files'][$format]) && $posttype['files'][$format] === false) {
                static::log('File format ' . $format . ' disallowed in post type config', null);
            } else {

                // attachments specified as URLs
                if (isset($data[$format])) {
                    if (V::url((string)$data[$format]) || (isset($data[$format]) && is_array($data[$format]) && V::url($data[$format]['value']))) {
                        if (V::url((string)$data[$format])) {
                            $remoteurl = $data[$format];
                            $alttext = '';
                        } else {
                            $remoteurl = $data[$format]['value'];
                            $alttext = $data[$format]['alt'] ?? '';
                        }
                        // TODO: DRY
                        if ($remoteurl) {
                            $remotefile = Remote::get($remoteurl);
                            $remoteinfo = $remotefile->info();
                            $name = F::safeName(basename($remoteinfo['url']));
                            $tempfile = $tempdir . substr(sha1(rand()), 0, 8) . '/' . $name;
                            F::write($tempfile, $remotefile->content());
                            $attachments[] = [ $name, $tempfile, $filetemplate, $format, $alttext ];
                        }
                    } else {
                        $count[$format] = 0;
                        foreach ($data[$format] as $upload) {
                            // limit to one file if set in config
                            $multiplefiles = $posttype['files'][$format][1] ?? true;
                            if ($multiplefiles === true || $count[$format] != 1) {
                                $remoteurl = null;
                                $alttext = '';
                                if (isset($upload['value']) && V::url($upload['value'])) {
                                    $remoteurl = $upload['value'];
                                } elseif (V::url($upload)) {
                                    $remoteurl = $upload;
                                }
                                if ($remoteurl) {
                                    $remotefile = Remote::get($remoteurl);
                                    $remoteinfo = $remotefile->info();
                                    $name = F::safeName(basename($remoteinfo['url']));
                                    $tempfile = $tempdir . substr(sha1(rand()), 0, 8) . '/' . $name;
                                    F::write($tempfile, $remotefile->content());
                                    $attachments[] = [ $name, $tempfile, $filetemplate, $format, $alttext ];
                                }
                                $count[$format]++;
                            }
                        }
                    }
                }

                // attachment included in multipart post request
                elseif ($uploads = kirby()->request()->files()->get($format)) {
                    if (array_key_exists('name', $uploads)) {
                        static::log('Single multipart file detected', print_r($uploads, true));
                        // if only one file exists, it needs to be wrapped into an array
                        $attachments[] = [ F::safeName($uploads['name']), $uploads['tmp_name'], $filetemplate, $format, '' ];
                    } else {
                        static::log('Multiple multipart files detected', print_r($uploads, true));
                        foreach ($uploads as $upload) {
                            $attachments[] = [ F::safeName($upload['name']), $upload['tmp_name'], $filetemplate, $format, '' ];
                        }
                    }
                }
            }
        }

        // log and return
        static::log('Array of attachment files to be saved ', print_r($attachments, true));
        return $attachments;
    }

}
