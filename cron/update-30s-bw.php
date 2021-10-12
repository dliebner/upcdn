<?php

define('IN_SCRIPT', 1);

$root_path = '/home/bgcdn/';

require($root_path . 'common.php');

$redis = new Redis();
$redis->pconnect('127.0.0.1');

$start = time();

// Run every second for 60 seconds
set_time_limit(60 * 2);
while( time() - 60 < $start ) {

	$bytes30s = (int)$redis->get('bgcdn:bw_30sec_exp_' . (time() + 1));

	ServerStatus::set('avg_bytes_per_sec_30s', round($bytes30s / 30) );
	ServerStatus::set('port_saturation', round($bytes30s * 8 / 30 / CDNTools::getPortSpeedBits(), 4) );

	sleep(1);

}
