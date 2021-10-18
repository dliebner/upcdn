<?php

define('IN_SCRIPT', 1);

$root_path = '/home/bgcdn/';

require($root_path . 'common.php');

$db = db();

$redis = new Redis();
$redis->pconnect('127.0.0.1');

$start = time();

// Run every second for 60 seconds
set_time_limit(60 * 2);
while( time() - 60 < $start ) {

	// Redis: Get and delete curent bandwidth chunk bytes value
	$ret = $redis->multi()
		->hGetAll('bgcdn:404_uris')
		->del('bgcdn:404_uris')
		->exec();

	if( $ret ) {

		foreach( array_keys($ret) as $path ) {

			/**
			 * fuck TODO:
			 * 	Implement valid matching patterns
			 * 	Build list of URIs/paths to send to File Oracle
			 * 	Query File Oracle for file details
			 * 	Retrieve files from B2
			 * 	Do any further processing as needed (such as unzipping)
			 */

		}

	}

}
