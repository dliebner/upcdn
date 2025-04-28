<?php

define('IN_SCRIPT', 1);

$root_path = '/home/upcdn/';

require($root_path . 'common.php');
$start = time();

$cronAlreadyRunning = Config::get('second_cron_running');

register_shutdown_function(function() use ($cronAlreadyRunning) {

	if( !$cronAlreadyRunning ) Config::set('second_cron_running', '');
	
});

// Run every second for 60 seconds
set_time_limit(60 * 2);
while( time() - 60 < $start ) {

	start_timer('doStuff');

	if( $cronAlreadyRunning ) {

		Config::loadConfig(true);
		if( !Config::get('second_cron_running') ) {

			$cronAlreadyRunning = false;
			Config::set('second_cron_running', 1);

		}

	}

	if( !$cronAlreadyRunning ) {

		Cron::downloadSourcesFromCloud();

	}

	$timeElapsed = stop_timer('doStuff');

	$waitSeconds = max(0, 1 - $timeElapsed);

	usleep($waitSeconds * 1000000);

}

Config::set('second_cron_running', '');