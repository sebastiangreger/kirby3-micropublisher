<?php

namespace sgkirby\Micropublisher;

return [
    [
        'pattern' => 'tokens',
        'method' => 'GET|POST',
        'action'  => function () {
            if (!empty(kirby()->request()->header('Authorization'))) {
                // confirm token validity to Micropub endpoint
                return Token::verifyAccessToken();
            } elseif (kirby()->request()->is('POST')) {
                // check authentication with Auth endpoint; create and send token to Micropub client
                return Token:: verifyAuthCode();
            }
        }
    ],
    [
        'pattern' => option('sgkirby.micropublisher.endpoint', 'micropub'),
        'method' => 'GET|POST',
        'action'  => function () {
            // handle request to create post from Micropub client
            return Micropublisher::endpoint();
        }
    ],
];
