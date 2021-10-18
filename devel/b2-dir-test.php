<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

error_reporting(E_ALL);

use obregonco\B2\Client;
use obregonco\B2\Bucket;
use obregonco\B2\ParallelUploader;
use obregonco\B2\DirectoryUploader;

const B2_DEBUG_ON = true;

$client = new Client(Config::get('b2_master_key_id'), [
	'keyId' => Config::get('b2_application_key_id'), // optional if you want to use master key (account Id)
	'applicationKey' => Config::get('b2_application_key'),
]);
$client->version = 2; // By default will use version 1

start_timer('upload');

$bucketId = $client->getBucketFromName('bidglass-creatives')->getId();

$dup = new DirectoryUploader($root_path . 'www/game_hls/', 'game_hls', $client, $bucketId);

$files = $dup->doUpload();

echo "\n" . stop_timer('upload') . "\n";
