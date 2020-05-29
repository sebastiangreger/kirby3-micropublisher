<?php

function micropublisherEndpoints() {

	// setting this option to empty disables output of the default indieauth endpoint (f.ex. when using selfauth)
    if (option('sgkirby.micropublisher.auth.authorization-endpoint') !== '') {
        $auth_endpoint = '<link rel="authorization_endpoint" href="' . option('sgkirby.micropublisher.auth.authorization-endpoint') . '" />';
    } else {
        $auth_endpoint = '';
    }

	echo $auth_endpoint
		. '<link rel="token_endpoint" href="' . option( 'sgkirby.micropublisher.auth.token-endpoint', 'https://tokens.indieauth.com/token' ) . '" />'
		. '<link rel="micropub" href="' . kirby()->urls()->base() . '/' . option( 'sgkirby.micropublisher.endpoint', 'micropub' ) . '" />';

}
