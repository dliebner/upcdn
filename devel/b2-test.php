<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

error_reporting(E_ALL);

$guzzleClient = new \GuzzleHttp\Client;
$b2Client = CDNClient::getB2Client();
$downloader = new MissingFileDownloader($guzzleClient, $b2Client);

start_timer('download');

$downloader->addFileToDownload(new MissingFile(
    './dfile',
    'false',
    'fake/path'
));

$downloader->doDownload();

print_r([
    'downloaded' => $downloader->getAllDownloadedFiles(),
    'failed' => $downloader->getAllFailedFiles()
]);

echo "\n" . stop_timer('download') . "\n";
