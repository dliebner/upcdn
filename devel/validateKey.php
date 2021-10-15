<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

error_reporting(E_ALL);

CDNClient::postToHub(CDNClient::HUB_ACTION_VALIDATE_KEY, [], [
	'success' => function() {

		echo "Secret key validated.";

	}, 'error' => function($message, $data) {

		echo "Validation error: " . $message . "\n\n";

		print_r($data);

	}
]);