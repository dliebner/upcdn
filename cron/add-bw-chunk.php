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
		->get('bgcdn:bw_chunk')
		->del('bgcdn:bw_chunk')
		->exec();

	if( $chunkBytes = (int)$ret[0] ) {

		// Add chunk bytes to running total
		$sql = "INSERT INTO bandwidth_logs (`month`, bytes_out)
		SELECT * FROM (
			SELECT LAST_DAY(NOW() - INTERVAL 1 MONTH) + INTERVAL 1 DAY as `month`, $chunkBytes as bytes_out
		) as aux
		ON DUPLICATE KEY UPDATE
			bandwidth_logs.bytes_out = bandwidth_logs.bytes_out + aux.bytes_out";

		if( !$db->sql_query($sql) ) {

			throw new QueryException("Error inserting", $sql);

		}

	}

	ServerStatus::setMulti([
		'monthly_bandwidth_used_pct' => round(CDNTools::getMonthlyBandwidthUsedPct(), 4),
		'projected_monthly_bandwidth_used_pct' => round(CDNTools::getProjectedMonthlyBandwidthUsedPct(), 4),
	]);

	sleep(1);

}
