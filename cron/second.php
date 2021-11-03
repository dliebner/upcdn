<?php

define('IN_SCRIPT', 1);

$root_path = '/home/bgcdn/';

require($root_path . 'common.php');
$start = time();

// Run every second for 60 seconds
set_time_limit(60 * 2);
while( time() - 60 < $start ) {

	start_timer('doStuff');

		

	$timeElapsed = stop_timer('doStuff');

	$waitSeconds = max(0, 1 - $timeElapsed);

	usleep($waitSeconds * 1000000);

}
