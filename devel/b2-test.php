<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

use obregonco\B2\Client;
use obregonco\B2\Bucket;

$client = new Client(Config::get('b2_master_key_id'), [
	'keyId' => Config::get('b2_application_key_id'), // optional if you want to use master key (account Id)
	'applicationKey' => Config::get('b2_application_key'),
]);
$client->version = 2; // By default will use version 1

// Retrieve an array of Bucket objects on your account.
$buckets = $client->listBuckets();

print_r($buckets);
