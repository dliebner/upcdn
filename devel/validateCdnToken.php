<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

error_reporting(E_ALL);

echo CDNClient::validateCdnToken("", 'upload-video', "", 99) ? "yes" : "no";
echo "\n";
