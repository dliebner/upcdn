<?php

$root_path = './../';

require_once( $root_path. 'vendor/autoload.php' );

use obregonco\B2\Client;
use obregonco\B2\Bucket;

$client = new Client('002ef553d27505e0000000001', [
	//'keyId' => '', // optional if you want to use master key (account Id)
	'applicationKey' => 'K002WhVSExFkgz27fDhfwfGGJrBoWKk',
]);
$client->version = 2; // By default will use version 1

// Retrieve an array of Bucket objects on your account.
$buckets = $client->listBuckets();

print_r($buckets);
