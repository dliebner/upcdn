<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

error_reporting(E_ALL);

use obregonco\B2\Client;
use obregonco\B2\Bucket;
use obregonco\B2\ParallelUploader;

$client = new Client(Config::get('b2_master_key_id'), [
	'keyId' => Config::get('b2_application_key_id'), // optional if you want to use master key (account Id)
	'applicationKey' => Config::get('b2_application_key'),
]);
$client->version = 2; // By default will use version 1

$bucketId = $client->getBucketFromName('bidglass-creatives')->getId();

$pup = new ParallelUploader($client, $bucketId);

$pup->addFileToUpload([
    'FileName' => 'test/test.txt',
    'LocalFile' => "./test.txt"
]);

for( $i = 1; $i <= 10; $i++ ) {

    $pup->addFileToUpload([
        'FileName' => "test/test$i.txt",
        'LocalFile' => "./test$i.txt"
    ]);

}

$files = $pup->doUpload();

print_r($files);
