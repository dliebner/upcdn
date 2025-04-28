<?php

define('IN_SCRIPT', 1);

$root_path = '/home/upcdn/';

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
use dliebner\B2\ParallelUploader;

// Make sure to clear cron_cloud_upload_start on exit
register_shutdown_function(function() {

	Config::set('cron_cloud_upload_start', '');
	
});

const B2_DEBUG_ON = true;

while( time() - $maxWaitTime < $start ) {

	start_timer('cronLoop');

	/** @var TranscodingJob[] */
	if( $tJobs = TranscodingJob::getCloudUploadJobs() ) {

		Config::set('cron_cloud_upload_start', time());

		$srcCloudUploads = [];
		$versionCloudUploads = [];

		$client = CDNClient::getB2Client();
		$bucketId = $client->getBucketFromName(Config::get('b2_bucket_name'))->getId();
		$pup = new ParallelUploader($client, $bucketId);
		$pup->numUploadLanes = TranscodingJob::CLOUD_UPLOAD_MAX_CONCURRENT;

		foreach( $tJobs as $job ) {

			if( $job->srcIsNew && !$job->data['src_cloud_upload_started'] ) {

				$srcCloudUploads[] = $job;

				$pup->addFileToUpload($file = [
					'upcdn:jobId' => $job->id,
					'upcdn:type' => 'src',
					'FileName' => $job->getSrcCloudPath(),
					'LocalFile' => $job->inProgressPath()
				]);

				//print_r($file); echo "\n";

			}
			
			if( $job->data['transcode_is_finished'] && !$job->data['cloud_upload_started'] ) {

				$versionCloudUploads[] = $job;

				if( $job->isHls() ) {

					// Zipped HLS files
					$localFile = VideoPath::hlsZipLocalPath($job->versionFilename);

				} else {

					// mp4 file
					$localFile = VideoPath::mp4LocalPath($job->versionFilename);

				}

				$pup->addFileToUpload($file = [
					'upcdn:jobId' => $job->id,
					'upcdn:type' => 'version',
					'FileName' => $job->getVersionCloudPath(),
					'LocalFile' => $localFile
				]);

				//print_r($file); echo "\n";

				// Add any posters
				$posterLocalWildcardPath = VideoPath::posterLocalPath($job->srcFilename, '*');

				$files = new GlobIterator($posterLocalWildcardPath);
				foreach( $files as $file ) {

					if( preg_match('/^[^.]+_poster_(\d+)\.jpg$/', $file->getFilename(), $matches) ) {

						$posterFrameIndex = $matches[1];

						$pup->addFileToUpload($fileObj = [
							'upcdn:jobId' => $job->id,
							'upcdn:type' => 'poster',
							'FileName' => VideoPath::getPosterCloudPath($job->srcFilename, $posterFrameIndex),
							'LocalFile' => $file->getPathname()
						]);

						//print_r($fileObj); echo "\n";

					}

				}

			}

		}

		TranscodingJob::setSrcCloudUploadStarted($srcCloudUploads);
		TranscodingJob::setCloudUploadStarted($versionCloudUploads);

		$pup->doUpload();

		if( $uploadedFiles = $pup->getAllUploadedFiles() ) {

			$finishedSrcCloudUploadJobIds = [];
			$finishedCloudUploadJobIds = [];

			foreach( $uploadedFiles as $uploadedFileResult ) {

				$type = $uploadedFileResult->originalFile['upcdn:type'];
				$jobId = $uploadedFileResult->originalFile['upcdn:jobId'];

				if( $type === 'src' ) $finishedSrcCloudUploadJobIds[] = $jobId;
				if( $type === 'version' ) $finishedCloudUploadJobIds[] = $jobId;

			}

			TranscodingJob::setSrcCloudUploadFinished($finishedSrcCloudUploadJobIds);
			TranscodingJob::setCloudUploadFinished($finishedCloudUploadJobIds);

		}

		if( $failedFiles = $pup->getAllFailedFiles() ) {

			$failedSrcCloudUploadJobIds = [];
			$failedCloudUploadJobIds = [];

			foreach( $uploadedFiles as $uploadedFileResult ) {

				$type = $uploadedFileResult->originalFile['upcdn:type'];
				$jobId = $uploadedFileResult->originalFile['upcdn:jobId'];

				if( $type === 'src' ) $failedSrcCloudUploadJobIds[] = $jobId;
				if( $type === 'version' ) $failedCloudUploadJobIds[] = $jobId;

			}

			TranscodingJob::unsetSrcCloudUploadStarted($finishedSrcCloudUploadJobIds);
			TranscodingJob::unsetCloudUploadStarted($finishedCloudUploadJobIds);

			Logger::logEvent('b2_failed_upload', [
				'data' => [
					'failedFiles' => $failedFiles
				]
			]);

			echo "Failed files:\n";
			print_r($failedFiles);
			echo "\n";

		}

	}

	$timeElapsed = stop_timer('cronLoop');
	$waitSeconds = max(0, 1 - $timeElapsed);

	usleep($waitSeconds * 1000000);

}

Config::set('cron_cloud_upload_start', '');
