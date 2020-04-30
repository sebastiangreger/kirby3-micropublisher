<?php

namespace sgkirby\Micropublisher;

use Kirby\Http\Remote;
use Kirby\Http\Response;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\V;

class Token
{
    /*
     * Here, the Micropub endpoint calls the token endpoint to check that the access token it received
     * from the Micropub client (alongside a Micropub publishing request) is valid (i.e. that it had
     * been created by this token endpoint)
     */
    public static function verifyAccessToken()
    {
        // obtain the token from the request header
        $accesstoken = kirby()->request()->header('Authorization');

        // decode the JWT token
        $encryption_key = option('sgkirby.micropublisher.jwtkey');
        if (strlen($encryption_key) < 10) {
            return new Response('Invalid token in setup.', 'text/html', 500);
        } else {
            $decoded = \Firebase\JWT\JWT::decode(str_replace('Bearer ', '', $accesstoken), $encryption_key, ['HS256']);
        }

        // return scope and identity back to the Micropub endpoint
        // TODO: return as html or json, depending on headers (see selfauth)
        return new Response('scope=' . $decoded->scope . '&me=' . $decoded->me, 'text/html', 200);
    }

    /*
     * Here, the Micropub client (usually during user login to the client) calls the token endpoint
     * to exchange the auth code it retrieved from the Auth endpoint for an access token that is later
     * needed for actual publishing operations
     *
     * Looks for and polls the authorisation endpoint (e.g. kirby3-selfauth plugin or indieauth.com)
     * as indicated by the Micropub client; verifies the given auth code with it and returns access token
     */
    public static function verifyAuthCode()
    {
        // discover auth endpoint from the website given as 'me' property
        $response = Remote::get($_POST['me']);
        if (preg_match_all('!\<link(.*?)\>!i', $response->content(), $links)) {
            foreach ($links[0] as $link) {
                if (!Str::contains($link, 'rel="authorization_endpoint"')) {
                    continue;
                }
                if (!preg_match('!href="(.*?)"!', $link, $match)) {
                    continue;
                }
                $endpoint = $match[1];
                if (!V::url($endpoint)) {
                    continue;
                }
            }
        }
        if (empty($endpoint)) {
            return new Response('Cannot discover auth endpoint from site HTML.', 'text/html', 500);
        }

        // send the provided auth information to the authorisation endpoint for verification
        $response = new Remote($endpoint, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/x-www-form-urlencoded',
            ],
            'data' => [
                'code' => $_POST['code'],
                'me' => $_POST['me'],
                'redirect_uri' => $_POST['redirect_uri'],
                'client_id' => $_POST['client_id'],
            ],
        ]);

        // parse authorisation and scope returned by the authorisation endpoint
        // TODO: deal with response in JSON (or send accept headers for html only)
        parse_str($response->content, $auth);
        $scope = $auth['scope'];
        $me = $_POST['me'];

        // create the access token for the Micropub client
        $token_data = [
            'me' => $me,
            'client_id' => $_POST['client_id'],
            'scope' => $scope,
            'date_issued' => date('Y-m-d H:i:s'),
            'nonce' => mt_rand(1000000, pow(2, 31))
        ];
        // this uses JSON Web Token; change the jwtkey to invalidate previous tokens
        $encryption_key = option('sgkirby.micropublisher.jwtkey');
        if (strlen($encryption_key) < 10) {
            return new Response('Invalid token in setup.', 'text/html', 500);
        } else {
            $token = \Firebase\JWT\JWT::encode($token_data, $encryption_key);
        }

        // hand the token back to the Micropub client
        // TODO: return as html or json, depending on headers (see selfauth)
        return new Response('access_token=' . $token . '&scope=' . $scope . '&me=' . $me, 'application/x-www-form-urlencoded', 200);
    }
}
