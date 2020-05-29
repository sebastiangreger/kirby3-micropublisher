<?php

/**
 * Kirby 3 Micropublisher
 *
 * @version   1.0-beta.3
 * @author    Sebastian Greger <msg@sebastiangreger.net>
 * @copyright Sebastian Greger <msg@sebastiangreger.net>
 * @link      https://github.com/sebastiangreger/kirby3-micropublisher
 * @license   MIT
 */

@include_once __DIR__ . '/vendor/autoload.php';

Kirby::plugin('sgkirby/micropublisher', [

    'options' => [
        'slugprefix'                    => '',
        'auth.authorization-endpoint'   => 'https://indieauth.com/auth',
        'auth.token-endpoint'           => 'https://tokens.indieauth.com/token',
        'syndicate-to'                  => [],
        'categorylist.parent'           => 'home',
        'categorylist.taxonomy'         => 'tags',
    ],

    'routes' => require __DIR__ . '/config/routes.php',

]);
