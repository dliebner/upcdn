<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

CDNClient::postToHub(CDNClient::HUB_ACTION_VALIDATE_KEY, [], [
	'success' => function() {

		echo "Secret key validated.";

	}
]);