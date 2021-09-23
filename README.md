# Kirby3 Micropublisher

⚠ _This plugin is in Beta stage; expect breaking changes that are not backward compatible between versions until it reaches v1.0 stable! You are invited to play around with it (use the Kirby Starterkit for an easy start) and report any problems or observations as an [Issue](https://github.com/sebastiangreger/kirby3-micropublisher/issues); please subscribe to "Watch releases" if you want to get updated on new releases._

## Overview

An adaptable [Micropub](https://indieweb.org/micropub) server for Kirby3, designed to be highly adjust- and extendable.

![translator](https://user-images.githubusercontent.com/6355217/80856036-bc1f7900-8c46-11ea-89fb-5bf986c9cff5.png)

## Installation

### Composer

```bash
composer require sgkirby/micropublisher
```

### Git submodule

```bash
git submodule add https://github.com/sebastiangreger/kirby3-micropublisher.git site/plugins/kirby3-micropublisher
```

### Download

Download and copy this repository to `/site/plugins/kirby3-micropublisher`.

## Setup

_NB. This plugin does not work without the following steps._

### 1. Set up IndieAuth

Since Micropub uses the OAuth-based IndieAuth protocol for user identification, this plugin won't work until your site is set up to support IndieAuth. Unless your site is already set up for IndieAuth, follow the instructions in this [Cookbook recipe](https://getkirby.com/docs/cookbook/integrations/indieauth) for third-party authentication or use the [kirby3-selfauth plugin](https://github.com/sebastiangreger/kirby3-selfauth) for an entirely self-hosted IndieAuth endpoint.

The [IndieAuth setup page](https://indieauth.com/setup) provides a tool to test your setup. A successfully performed login on IndieAuth.com using your site's URL is the precondition for Micropub, and this plugin, to work.

### 2. Set a token secret

In addition to the IndieAuth authentication, the Micropub workflow requires a so-called "token endpoint"; it deals with setting a scope of allowed actions for the authenticated user. The token endpoint is built-in with the plugin, but requires a unique key to be set up in `site/config/config.php` - this has to be a random alphanumeric string of at least 10 characters:

```php
'sgkirby.micropublisher.jwtkey' => '<YOUR-KEY-STRING>',
```

### 3. Link your Micropub endpoints in your template

Micropub software clients look up your endpoints' addresses from your HTML meta information. Add the following to the \<head\> section of your HTML template:

```php
<?php micropublisherEndpoints() ?>
```

This renders the required <link> tags to inform Micropub clients how to access your server:

```html
<link rel="token_endpoint" href="https://<SITE-URL>/tokens" />
<link rel="micropub" href="https://<SITE-URL>/micropub" />
```

_NB. Remember to empty any server-side caches before proceeding and after any changes to the configuration as of below._

### 4. Set up your in individual processing rules

The default settings of the plugin correspond to the [Kirby Starterkit](https://getkirby.com/try). For use with any other setup, further configuration will likely be needed; see the [Configuration](#configuration) section below.

## Use

After all three setup steps have been concluded, your site is ready to receive content from Micropub clients.

Launch a Micropub client, e.g. [Quill](https://quill.p3k.io/) for your first steps, and log in by providing the URL of your website. The client will lead you through the IndieAuth authorisation flow. Make sure to grant the application at least "create" rights - otherwise it won't be able to post to your site. Enter the Text of your post into the client and select "Publish". Your post should be created on the website and your browser forwards to the URL of the published post.

Indieweb.org has a [list of Micropub clients](https://indieweb.org/Micropub/Clients). This plugin has been tested for core compatibility with the following clients (though it may not support every feature of each client):
* [Quill](https://quill.p3k.io/) in your browser
* [Indigenous for Android](https://indieweb.org/Indigenous_for_Android) on your smartphone
* [shpub](http://cweiske.de/shpub.htm) on the Linux command line

## Configuration

Micropub is intended to unify the experience of posting to a website using a standardized protocol, giving users free choice of tools to create content. Since no site is like the other, this inevitably comes with setup needs on the server side - while the incoming content sent from the Micropub client follows a [specified syntax](https://indieweb.org/Micropub#Syntax), a flexible CMS like Kirby means there is no way to universally pre-configure how to store the content (except when using the Starterkit, which the plugin's default settings correspond to).

In order to ensure best utility, the Micropublisher plugin is designed to be highly configurable. This makes it extremely flexible, but requires careful work to set up.

The test suite at https://micropub.rocks is highly recommended during setup. In addition to providing an easy way to test your implementation, it displays the server response in case of errors which may help tracking down issues (activating Kirby's [debug mode](https://getkirby.com/docs/guide/troubleshooting/debugging) helps greatly). The plugin's log file at `site/logs/micropublisher/micropub.log` further helps to assess the data flow behind the scenes - it always contains the full protocol of the last received Micropub content.

## Options

The plugin can (and, unless you run a barebone Starterkit-based website, will have to) be configured by adding settings to your `site/config/config.php`.

### Token secret

The built-in token endpoint requires a unique key to sign the permission tokens - this has to be a random alphanumeric string of at least 10 characters:

```php
'sgkirby.micropublisher.jwtkey' => '<YOUR-KEY-STRING>',
```

_NB. Changing this token later invalidates existing logins._

### Defaults

Using specific rule sets, it is possible to set a different template, page status, parent page and slug design for every type of post you defined for your Micropub endpoint. However, default values are defined as a fallback and will likely have to be adjusted. By setting these to the most common values on your site you can reduce the complexity of specific rules to be defined later; as a matter of fact you might get away with only the default settings if you don't need support for several formats.

#### Default template

By default, new posts are created using the Kirby template `note` (i.e. the resulting text file is `note.txt`; this stems from the Starterkit theme). If your site uses a different template for posts, e.g. `post`, add the following setting:

```php
'sgkirby.micropublisher.default.template' => 'post',
```

#### Default status

Since the idea of Micropub is fast publishing, posts created via the Micropub endpoint are by default created as `listed` (i.e. published and listed on your Kirby site). If you'd rather change the default to create all posts as unpublished drafts, add the following line (the possible values are `listed`, `unlisted` and `draft`):

```php
sgkirby.micropublisher.default.status' => 'draft',
```

#### Default parent

Every site has a different structure. Following the Starterkit's hierarchy, the default is to create new posts as a child to page 'notes'. You will likely have to change that (note that the plugin will throw an error when posting to a non-existent parent). To do this, enter the ID of the page (e.g. `blog` or `blog/notes`) as a setting:

```php
sgkirby.micropublisher.default.parent' => 'blog',
```

#### Default slug

The URL slug is by default created from a submitted slug property; as last resort fallback, an epoch timestamp is used. This default behaviour can be changed globally. The following example adds the default setting array to the config (for a detailed explanation of the syntax and how to build more complex slug rulesets see [below in the post type field definition](#9-slug-design-rules-optional) which uses the same syntax):

```php
'sgkirby.micropublisher.default.slug' => 'slug',
```

#### Default rendering rules

The transposition of the data properties from the Micropub client into page fields for Kirby happens according to so called "rendering rules". The syntax is [explained below](#7-rendering-rules) for defining post-type-specific rules, but on a simple setup with only one supported post format, it could be enough to adapt the plugin's default rendering ruleset. The following example would define a default set of rules corresponding to the built-in rules applicable for the Starterkit:

```php
'sgkirby.micropublisher.default.render' => [
  'name'		=> [ 'title', 'No title' ],
  'content'	=> [ 'text', '' ],
  'category'	=> [ 'tags', null ],
  'published'	=> [ 'date', strftime( '%F %T' ), 'datetime' ],
],
```

This example transposes Micropub's `name` property to an assumed Kirby page field `title` with a fallback of "No title", stores the content from the `content` property to the field `text`, saves any "categories" submitted by the client into a `tags` field and stores either the `published` property or the current timestamp in the page field `date`.

_NB. While the Microformat properties in the Micropub payload are defined by the standard, the field names and data formats for the Kirby page depend on the blueprint in use._

### Post types

This is the most important and most powerful (and at first sight, admittedly, also most intimidating) setting of the Micropublisher plugin. It enables fine grained control over content creation.

"Post types" are representations of content on your website; for example you might have a post type "article" for longform blog posts, "note" for microblogging and "checkin" for posting locations. Post types commonly differ from each other by template used, blueprint fields, file attachments etc.

Figuratively spoken, this array structure acts as a "translator" that interprets the incoming Micropub request (which is a content representation in Microformats) and assembles the Kirby page it should be turned into (an array of fields to create the new page from).

![posttypes](https://user-images.githubusercontent.com/6355217/80856035-bb86e280-8c46-11ea-9a6f-a9308f3338e7.png)

Every aspect of the following example is explained with the according index number (*1 etc.) below. Most fields are optional (falling back to the defaults hardwired into the plugin or adjusted with the settings described above), but the Micropub endpoint will return an error if not at least one entry under `fields` is set for every post type.

```php
'sgkirby.micropublisher.posttypes' => [
  // internal name of post type (unique)  *1
  'note' => [
    // human-readable name (optional)  *2
    'name'	=> 'Note',
    // Kirby template name (optional)  *3
    'template'	=> 'note',
    // status of the newly created post (optional)  *4
    'status'		=> 'listed',
    // the parent page for new posts of this type (optional)  *5
    'parent'		=> 'notes',
    // the rules to identify incoming posts of this type (optional)  *6
    'identify' 	=> [
      'unique'	=> null,
      'has'		=> [ 'content' ],
      'hasnot'	=> null,
    ],
    // the rendering rules for this post type  *7
    'render' 	=> [
      'name'		=> [ 'title', 'No title' ],
      'content'	=> [ 'text', '', function( $value, $fieldname, $default ) {
        return $value
      } ],
      'category'	=> [ 'tags' ],
      'published'	=> [ 'date', strftime( '%F %T' ), 'datetime' ],
      'checkin'	=> [ 'checkin', null, 'yaml' ],
    ],
    // handling of uploaded media files (optional)  *8
    'files'		=> [
      'photo'		=> [ 'image', true, false ],
    ],
    // slug building rules (optional)  *9
    'slug' => [ 'slug', 'title', [ 'text', 30 ] ],
    // target language (optional)  *10
    'language' => 'en',
  ],
],
```

#### 1. Internal name (unique)

This has to be a unique name, with no spaces or special characters; all lower-case characters preferred.

#### 2. Name (optional)

Some Micropub clients retrieve a list of supported post types and display them as a selection menu in the UI. Hence, every post type should have a human-readable name. This can be anything that makes sense to you in the client UI, as it is not used programatically.

If no name is given, the internal name (#1 above) is used.

#### 3. Kirby template name (optional)

For each post type, you may indicate a Kirby content template to be used, overriding any global defaults.

If no template name is indicated, the global default template ('note') or, if defined, the global setting [`sgkirby.micropublisher.default.template`](#default-template) from config.php is used.

#### 4. Page status (optional)

This setting allows granular control whether new content of this particular post type is created as a draft or a published post (possible values are Kirby's `draft`, `unlisted` or `listed`). Two different modes are available.

##### Allow client override

Some Micropub clients support setting a `post-status` property for post-by-post control. Since this property only knows two states ("publish" and "draft"), the status property in the post types array needs to be set up with an array; its first value corresponds to the user choice "published" (and is also the fallback in case a Micropub client does not submit this field), while the second is the status given to that post if the client submits a `post-status=draft` property.

The following example would treat posts of this type as `listed` by default, but as `draft` if chosen in and transmitted from the Micropub client:

```php
'status'	=> ['listed', 'draft'],
```

Above example is also the plugin's default, if no `status` property is indicated in the post type definition; this default can be changed via the global setting [`sgkirby.micropublisher.default.status`](#default-status) in config.php.

##### Enforced status

By providing only a string as the `status` property, any post of this type will be forced to be published with this status. This overrides a user choice of status in the Micropub client as well as all defaults in the plugin.

```php
'status'	=> 'listed',
```

#### 5. Parent page (optional)

Defines the parent page for creating new posts of this post type. This allows defining specific "destinations" for every post type separately.

If no parent page is indicated, the global default ('notes') or, if defined, the global setting [`sgkirby.micropublisher.default.parent`](#default-parent) from config.php is used.

#### 6. Identification rules (optional)

Since Micropub itself does not provide a means to indicate different post types, the Micropublisher plugin uses a rule definition set - the `identify` array to analyze and recognize what post type to create, based on the Microformat properties submitted in the Micropub payload.

The post types are processed in the order they appear in the definition array and the first post type where all conditions are met is used. A type with no or an invalid `identify` array is treated as a catch-all, triggered for any submission not caught by a rule earlier in the array.

If one post type combines multiple rules (e.g. both a "has" and a "hasnot" rule), all conditions have to match to qualify as this post type.

##### unique

If the presence of a certain Microformat property uniquely indicates a Micropub post to be of the post type, its name can be stated as `unique` in its rule set.

For example, it would be possible to detect a common "reply" post from an incoming Micropub request by stating that this post type is to be used if the Microformat property `location` is present in the payload:

```php
'identify' 	=> [
  'unique'	=> 'reply-of',
],
```

_NB. the value for the `unique` variable is a string_

Since a `unique` rule assumes a unique condition, the example above would render any request containing a location property to be of this post type, no matter what. In case multiple post types claim the same Microformat property as unique trigger, the first one in the post types array takes precedence.

##### has/hasnot

The `has` and `hasnot` values are arrays of Microformat property names that further help to detect a specific post type. A post type is deemed applicable if the request contains all Microformat properties stated as "has" and none of those stated as "hasnot".

For example, a "checkin" type post (posting a geolocation) could be detected by testing for the presence of Microformat properties `content` and `location`, if the Micropub client used is known to always send checkins with both GPS coordinates and a title string:

```php
'identify' => [
  'has'	   => ['name', 'location'],
],
```

This also allows to identify post types based on attachments: for example to make a rule not match for a submission that contains a photo, we can define:

```php
'identify' => [
  'hasnot' => ['photo'],
],
```

_NB. the value for the `has` and `hasnot` variables are an array of strings_

#### 7. Rendering rules

Each property within the array `render` represents one value from the Micropub payload to be processed. The properties submitted from the posting UI depend on the client and/or the post type (e.g. some of the less common properties are: summary, location, in-reply-to, like-of, repost-of, syndication, bookmark-of, ...).

The property is named with the name of the microformat property from the request; this is the property to be "rendered".

The value of each property defines how the microformat property is to be rendered into a Kirby page field; it is an array with the following values in this order:
1. The name of the blueprint field this content is to be stored in (string); e.g. the value from Microformat property `name` might be rendered into the title to be stored in a blueprint field called `title`
2. (optional) A default string in case this property is empty (null/string); an array may also be given, but will be translated into a comma-separated string
3. (optional) Instructions for special treatment of the received value (string/function); here, either a string with the name of a preset (currently: 'datetime' to return a Kirby-formatted date string, 'json' to translate the content into a JSON field, or 'yaml' to translate the content into a YAML structure field) or an anonymous callback function can be given (see examples below).

##### Minimum rule

The minimal requirement is to map the Microformat property (as contained in the Micropub request) on the left to a Kirby blueprint field in an array on the right. This rule would simply save the tags submitted as `category` into the blueprint field `tags` and assign an empty string as fallback:

```php
'render' => [
  'category' => ['tags'],
```

##### Fallback value

This example would take the value that the Micropub client submits as Microformat property `name` and store it into the Kirby content file for field `title`; in case the request's `name` value is empty, the fallback string "No title" is saved instead:

```php
'render' => [
  'name' => ['title', 'No title'],
```

##### Preset: datetime

Likely applying to most Kirby setups where posts store a creation date, the next example is a typical case where the `published` value, as defined by Micropub and Microformats, needs to be stored in a Kirby blueprint field called `date` (as is for example the case in the Starterkit). In order to ensure a creation date is always stored (even if not delivered via the Micropub request), the default value is set to be the accordingly formatted date/time string. The preset `datetime` as the third value triggers a hardwired routine that transforms almost any date format arriving via Micropub into the format required by Kirby - otherwise it falls back to what is defined as default value in the second field:

```php
'render' => [
  'published' => ['date', strftime('%F %T'), 'datetime'],
```

_NB. The `published` property is often omitted by Micropub clients (implicitly defined as "now" when missing), so it is a good idea to still set up a rendering rule for it and assign the current time as a fallback, if the blueprint expects a publication date._

##### Present: yaml

Some properties are specified to be sent via Micropub as data structures. For example "checkins" or location data. Unless you want to process these in more advanced fashion (see below), you can store such structure in a YAML formatted field (making it editable as a Structure field in a Panel blueprint):

```php
'render' => [
  'location'	=> ['checkin', null, 'yaml'],
```

##### Anonymous function

Using an anonymous function as a callback allows for fairly advanced processing of the content. The three (optional) variables are the value of the submitted field (\$value), the field name (\$field = the first parameter), and the default value (\$default = the second parameter). The function may return a string, which is then stored in the Kirby content file for the according field, or an array of multiple field values. The function in the following example would for instance not only modify the string, but also change the title field (here, order of the commands matters, as later changes will override the earlier - here, for example the title from processing the `name` Microformat value would be erased by the new value set by the function). The function has to always return either a string (then used as the final value to be saved for this field) or as an array (enabling to set multiple fields to be saved, including such that are not present in the post type fields definition, as long as it exists in the Kirby blueprint):

```php
'render' => [
  'content' => [ 'text', '', function( $value, $fieldname, $default ) {
    return [
      'title' => 'Modified',
      $fieldname => $value . ' and some more text',
  ]; }
],
```

For even more advanced functionalities, the anonymous function can be called using `function($value, $fieldname, $default) use (array $data) {}`, which then provides access to `$data`, exposing all the unprocessed data submitted via the Micropub protocol (minus some authentication-related parts for security).

#### 8. Accepted files (optional)

The Micropub specification allows for file attachments of three types: `photo`, `video`, and `audio`. This optional setting allows fine-grained control over these attachments.

The (optional) files variable contains one array for each of the three Micropub attachment file types, consisting of:
1. A string with the file blueprint name the uploaded media is to be stored as (default is the file type from the Micropub request, `photo`, `video`, or `audio`)
2. (optional) a boolean whether multiple files of this type are accepted (`false` to limit to one file of this type per post)
3. (optional; only for type `photo`) a string with the page blueprint's field name to add a reference of this file (the first one, if multiple) to the page data; this can be used to save one image as a "cover" photo

To disallow an attachment type for a page type, the boolean false can be used instead.

For example to limit photo uploads to one per post, and using the file blueprint `image`:

```php
'files'		=> [
  'photo'		=> ['image', false],
],
```

To only multiple photos per post of this post type, using the same file blueprint `image`, and adding a reference to the first one as field `cover` to the page data:

```php
'files'		=> [
  'photo'		=> ['image', true, 'cover'],
],
```

To disable video and audio uploads to a page type (photos remain allowed, since the default for a missing setup array is `true`):

```php
'files'		=> [
  'video'		=> false,
  'audio'		=> false,
],
```

#### 9. Slug design rules (optional)

For every post type, a separate rule set for building the slug can be defined. It consists of an array that defines an order of rules. Whichever rule is met by sufficient data in the post is used. The ultimate fallback (not in the ruleset, but hardwired) is always the UNIX timestamp.

This is the minimal definition, equal to the hardwired default; it uses the `mp-slug` (or deprecated `slug`) property if submitted by the Micropub client and not empty:

```php
'slug' => 'slug',
```

A common rule template for many purposes is to compensate a missing/empty `slug` property by (in that order) turning either the post title or an excerpt of the text into the slug (NB. here, the final field name from the Kirby blueprint has to be used, not the property name from the Micropub request). The following example illustrates that: the three values in the array are processed in the given order and the first rule leading to a valid slug is used - use the `mp-slug` property, turn the `title` field into a slug or create a slug from the first 30 characters of the `text` field:

```php
'slug' => [ 'slug', 'title', [ 'text', 30 ] ],
```

If no slug ruleset is provided for a post type: if defined, the globally defined rule set [`sgkirby.micropublisher.default.slug`](#default-slug) from config.php, or the global default (i.e. the `mp-slug` property if given, otherwise an epoch timestamp) is used.

#### 10. Target language (optional)

This setting only applies on multi-language sites.

By default, new micropub content is created in a site's default language. To override, a valid two-letter language code can be provided, to create a single post type's content in a specific language.

```php
'language' => 'en',
```

### Configuring the client UI

Micropub clients might query your Micropub endpoint to retrieve some settings. If you would like to make use of tags or categories and wish for them to show up in the according form field, you may tell the plugin what tags/categories to communicate to the client. For example, to provide a list of your existing tags to the client, the following two config lines indicate the parent of the pages to be polled for existing tags and the name of the field to be plucked:

```php
'sgkirby.micropublisher.categorylist.parent' => 'blog',
'sgkirby.micropublisher.categorylist.taxonomy' => 'tags',
```

### Niche settings

The following are niche settings likely irrelevant for most users. They are documented for completeness; if they don't make sense to you, they are likely for an edge case not applicable on your site.

To change the URL of the Micropub endpoint (default is `https://domain.tld/micropub`), add the following setting and change the string as desired:

```php
'sgkirby.micropublisher.endpoint' => 'micropub',
```

Some sites may use a post.create:after hook to alter the slug of newly created posts. A post created with slug 'lorem-ipsum' might for example be stored as '2020-01-lorem-ipsum'. To avoid conflicts with existing slugs, it is possible to tell the plugin to add a prefix to the slug before validating its availability:

```php
'sgkirby.micropublisher.slugprefix' => 'my-prefix-',
```

...or, to illustrate aforementioned example case with added date:

```php
'sgkirby.micropublisher.slugprefix' => date('Y') . '-' . date('m') . '-',
```

### Still to be documented

```php
'sgkirby.micropublisher.syndicate-to' => [],
```

## Features

### Plugin features

![micropubrocks](https://user-images.githubusercontent.com/6355217/80856033-ba55b580-8c46-11ea-823c-89891cc2da1b.png)

The following Micropub features are supported:
- [x] ...
- [x] Upload of files to a Micropub media endpoint

The following Micropub features are currently out of scope:
- [ ] editing existing posts
- [ ] deleting posts
- [ ] source queries

The following Kirby-specific features are supported:
- [x] HTML in incoming posts is translated into Markdown

The following [Micropub extensions](https://indieweb.org/Micropub-extensions) are supported:
- [x] mp-slug (set a URL slug in the client)
- [x] post-status (switch between draft/published in the client)
- [x] Query for Supported Vocabulary
- [x] Query for Category/Tag List
- [x] Media Endpoint Extensions

## Requirements

Kirby 3.3.2+(https://getkirby.com)

## Credits

Inspiration from:

- https://rhiaro.co.uk/2015/04/minimum-viable-micropub
- https://github.com/sebsel/kirby-micropub
- http://p.cweiske.de/363

Included vendor libraries:

- https://github.com/Elephant418/Markdownify (MIT)
- https://github.com/firebase/php-jwt (BSD3)

## License

Kirby 3 Micropublisher is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

Copyright © 2020 [Sebastian Greger](https://sebastiangreger.net)

It is discouraged to use this plugin in any project that promotes the destruction of our planet, racism, sexism, homophobia, animal abuse, violence or any other form of hate speech.
