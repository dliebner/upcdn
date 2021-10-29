<?php

define('IN_SCRIPT', 1);

$root_path = '/home/bgcdn/';

require($root_path . 'common.php');

// Runs once per minute

set_time_limit(60 * 120); // 120 minutes; 500mb file @ 1Mbit/s = 70 minutes

$start = time();
$maxWaitTime = 59;

// Wait if cron is already/still processing
$cronProcessing = false;
for( $i = 0; $i < floor($maxWaitTime); $i++ ) {
	
	Config::loadConfig(true);
	if( !$cronProcessing = (bool)Config::get('cron_cloud_upload_start') ) break;
	
	sleep(1);
	
}

if( $cronProcessing && Config::loadConfig(true) && Config::get('cron_cloud_upload_start') ) exit; // if cron is still processing at this point, exit without changing cron_cloud_upload_start

//
// Here is where we start the actual processing
//
use dliebner\B2\Client;
use dliebner\B2\AsyncUploadFileResult;
use dliebner\B2\ParallelUploader;

// Make sure to clear cron_cloud_upload_start on exit
register_shutdown_function(function() {

	Config::set('cron_cloud_upload_start', '');
	
});

while( time() - $maxWaitTime < $start ) {

	start_timer('cronLoop');

	/** @var TranscodingJob[] */
	if( $tJobs = TranscodingJob::getCloudUploadJobs() ) {

		Config::set('cron_cloud_upload_start', time());
		TranscodingJob::setCloudUploadStarted($tJobs);

		$client = new Client(Config::get('b2_master_key_id'), [
			'keyId' => Config::get('b2_application_key_id'), // optional if you want to use master key (account Id)
			'applicationKey' => Config::get('b2_application_key'),
		]);
		$client->version = 2; // By default will use version 1

		$bucketId = $client->getBucketFromName('bidglass-creatives')->getId();
		$pup = new ParallelUploader($client, $bucketId);
		$pup->numUploadLanes = TranscodingJob::CLOUD_UPLOAD_MAX_CONCURRENT;

		foreach( $tJobs as $job ) {

			$pup->addFileToUpload($file = [
				'bgcdn:jobId' => $job->id,
				'FileName' => $job->getCloudPath(),
				'LocalFile' => $job->inProgressPath()
			]);

			print_r($file);

		}

		$pup->doUpload();

		if( $uploadedFiles = $pup->getAllUploadedFiles() ) {

			$jobIds = array_map(function(AsyncUploadFileResult $uploadedFileResult) {

				return $uploadedFileResult->originalFile['bgcdn:jobId'];

			}, $uploadedFiles);

			TranscodingJob::setCloudUploadFinished($jobIds);

		}

		if( $failedFiles = $pup->getAllFailedFiles() ) {

			$jobIds = array_map(function($fileObj) {

				return $fileObj['bgcdn:jobId'];

			}, $failedFiles);

			TranscodingJob::unsetCloudUploadStarted($jobIds);

		}

	}

	$timeElapsed = stop_timer('cronLoop');
	$waitSeconds = max(0, 1 - $timeElapsed);

	usleep($waitSeconds * 1000000);

}

Config::set('cron_cloud_upload_start', '');
