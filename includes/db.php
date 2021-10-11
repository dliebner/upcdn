<?php

/*

	db.php
	
	Database Connector

								*/

if ( !defined('IN_SCRIPT') )
{
	die("Hacking attempt");
}

include($root_path . 'db/mysqli.php');

function db() {

	global $db;

	if( !$db instanceof sql_db ) {

		// Make the database connection.
		$db = $GLOBALS['db'] = new sql_db('localhost', 'bgcdn_user', MYSQL_BGCDN_PW, 'bgcdn_main', false);

		if( !$db->db_connect_id ) {

			die("Could not connect to the database.");

		}

		// Set connection encoding
		$db->sql_set_charset('utf8mb4');

	}

	return $db;

}
