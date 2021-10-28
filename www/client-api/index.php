<?php

define('IN_SCRIPT', 1);

$root_path = './../../';

include_once( $root_path . 'common.php' );
require_once( $root_path . 'includes/JSONEncrypt.php');

function debugEnabled() {

	return in_array($_SERVER['REMOTE_ADDR'], explode(",", Config::get('debug_ips')));

}

function default_exception_handler($e) {
	
	$eClass = get_class($e);
	
	if( in_array($eClass, array('QueryException','GeneralException','SilentAjaxException')) ) {
	
		handleAjaxException($e);
		
	} else {

		echo '<div>';
		echo '<b>Fatal error</b>:  Uncaught exception \'' . get_class($e) . '\' with message ';
		echo $e->getMessage() . '<br>';
		echo 'Stack trace:<pre>' . $e->getTraceAsString() . '</pre>';
		echo 'thrown in <b>' . $e->getFile() . '</b> on line <b>' . $e->getLine() . '</b><br>';
		echo '</div>';
		
	}

}

function handleAjaxException(Exception $e, $options = array()) {
		
	switch( get_class($e) ) {

		case 'GeneralException':

			AjaxResponse::returnError($e->getMessage(), null, $options);

		case 'GeneralExceptionWithData':

			AjaxResponse::returnError($e->getMessage(), $e->data, $options);
			
		case 'SilentAjaxException':

			die( AjaxResponse::status('silentError', $e->getMessage(), null, $options) );
		
		case 'QueryException':

			global $db;

			if( debugEnabled() ) {
			
				AjaxResponse::criticalDie(
					$e->getMessage() . "\n"
						. 'on line ' . $e->getLine() . "\n"
						. ' in ' . $e->getFile() . "\n\n"
						. $e->sql,
					array('sql' => $e->sql, 'err' => $db->sql_error()),
					$options
				);

			} else {
			
				AjaxResponse::criticalDie(
					$e->getMessage() . "\n"
						. 'on line ' . $e->getLine() . "\n"
						. ' in ' . $e->getFile(),
					[],
					$options
				);

			}
			
			break;
			
		default:

			AjaxResponse::criticalDie($e->getMessage(), null, $options);
			
			break;
		
	}
	
}

set_exception_handler('default_exception_handler');

/**
 * 	Client API
 * 		Actions require tokens granted by Hub server
 * 
 * 		cdn_tokens
 * 		- id
 * 		- action
 * 		- user_id (NULL)
 * 		- created
 * 		- expires
 * 		
 */

// CORS
$origin = $_SERVER['HTTP_ORIGIN'];

if( CDNClient::corsOriginAllowed($origin) ) {

	header('Access-Control-Allow-Origin: ' . $origin);

} else {

	exit;

}

$action = postdata_to_original($_POST['action']);

switch( $action ) {

	case 'upload-video':

		/**
			 Upload flow:
				- Upload video to the lowest-cpu server
				- Validate video file w/ ffprobe
				- Move file to in_progress folder
				- Add transcoding_jobs entry
					transcoding_jobs
					- id
					- src
					- started (NULL)
					- finished (NULL)
					- docker_container_id (NULL)
				- Transcode video
					Simultaneously upload original to cloud storage
				- Upload transcoded video to cloud storage
		*/
		$cdnToken = postdata_to_original($_POST['cdnToken']);
		$userId = (int)$_POST['userId'];

		if( !CDNClient::validateCdnToken($cdnToken, $action, $responseData, $_SERVER['REMOTE_ADDR'], $userId) ) {

			AjaxResponse::returnError("Invalid upload token.");

		}

		// Grab metadata
		$meta = $responseData['meta'];

		// Validate video file
		$fileUploadLimit = $responseData['fileUploadLimit'];
		$maxSizeBytes = $responseData['maxSizeBytes'];
		$maxDuration = $responseData['maxDuration'];

		//$originalFilename = $_FILES['image']['name'];
		$tmpFile = $_FILES['image']['tmp_name'];

		if( !is_uploaded_file($tmpFile) ) {

			AjaxResponse::returnError("Error reading the uploaded file.");

		}

		$fileSizeBytes = filesize($tmpFile);

		if( $fileSizeBytes > $fileUploadLimit ) {

			AjaxResponse::returnError("Max file size: " . humanFilesize($fileUploadLimit));

		}

		// ffprobe (TODO: functionize)
		$dir = escapeshellarg(dirname($tmpFile));
		$filename = escapeshellarg(basename($tmpFile));
		$cmd = escapeshellcmd(
			"sudo /home/bgcdn/scripts/docker-ffprobe.sh -d $dir -f $filename"
		);

		exec($cmd, $execOutput, $execResult);

		if( $execResult === 0 || $execResult === 1 ) {

			if( $ffprobeResult = json_decode(implode(PHP_EOL, $execOutput), true) ) {

				unset($execOutput);

				// Check probe result
				$probeResult = new FFProbeResult($ffprobeResult);

				/** @var FFProbeResult_VideoStream */
				if( !$videoStream = $probeResult->videoStreams[0] ) {

					AjaxResponse::returnError("Invalid video file.");

				}

				// Video encoding settings
				$targetBitRate = $responseData['bitRate'];
				$duration = $probeResult->duration;
				$targetSizeBytes = ceil($targetBitRate * $probeResult->duration / 8);
				$targetWidth = $responseData['width'];
				$targetHeight = $responseData['height'];
				$hlsByteSizeThreshold = $responseData['hlsByteSizeThreshold'];
				$passThroughVideo = $fileSizeBytes <= $maxSizeBytes && $videoStream->codecName === 'h264';

				// Source video info
				$sourceWidth = $videoStream->displayWidth();
				$sourceHeight = $videoStream->displayHeight();

				// Determine constraining width/height
				$uploadedAspectRatio = $videoStream->displayAspectRatioFloat;
				$targetAspectRatio = $targetWidth / $targetHeight;

				if( $uploadedAspectRatio > $targetAspectRatio ) {

					// Uploaded video is "wider" (proportionally) than the target dimensions
					$constrainWidth = $targetWidth;
					$constrainHeight = -1;

				} else {

					// Uploaded video is "taller" (proportionally) than the target dimensions
					$constrainWidth = -1;
					$constrainHeight = $targetHeight;

				}
				
				if( $passThroughVideo && $fileSizeBytes < $hlsByteSizeThreshold ) {

					// Don't need to save as HLS if we can passthrough and we're under the HLS byte size threshold
					$saveAsHls = false;

				} else {

					$passThroughVideo = false;

					$saveAsHls = $targetSizeBytes >= $hlsByteSizeThreshold;

				}

				if( !($passThroughVideo || $probeResult->duration <= $maxDuration) ) {

					AjaxResponse::returnError("Video must be under " . floor($maxDuration) . " seconds long.");

				}

				$sha1 = sha1_file($tmpFile);

				/**
				 * Submit to hub server to add video:
				 * 	- creative_video_src (if not exists based on file hash)
				 *  - creative_video_versions
				 * 
				 * Get:
				 * 	- src filename
				 * 	- version_filename?
				 */

				if( !CDNClient::createSourceVideo($meta, $sourceWidth, $sourceHeight, $fileSizeBytes, $duration, $ffprobeResult, $sha1, $hubResponseDataArray) ) {

					AjaxResponse::returnError("Error saving source video.");

				}

				$sourceFilename = $hubResponseDataArray['sourceFilename'];
				$sourceIsNew = $hubResponseDataArray['sourceIsNew'];
				$versionFilename = $hubResponseDataArray['versionFilename'];

				// Start new job
				$tcJob = TranscodingJob::create($sourceFilename, $sourceIsNew, $versionFilename, new TranscodingJobSettings(
					$targetBitRate,
					$constrainWidth,
					$constrainHeight,
					$passThroughVideo,
					$saveAsHls,
					null,
					true
				));

				// Move file to in-progress folder (/home/bgcdn/transcoding)
				if( !$tcJob->moveUploadedFile($tmpFile) ) {

					AjaxResponse::returnError("Error preparing transcoding job.");

				}

				$tcJob->startTranscode();

				// TODO: start the docker transcode and assign the docker container ID to the job

				if( $passThroughVideo ) {

					// Video is already under our target size and h264, just put into an mp4 container and remove metadata

				} else {

					// Add transcoding_jobs entry

				}

				// In-progress
				AjaxResponse::returnSuccess([
					'files' => $_FILES,
					'hubResponse' => $responseData,
					'cmd' => $cmd,
					'result' => $execResult,
					'output' => $ffprobeResult,
					'probeResult' => $probeResult,
					'transcodeTargets' => [
						'bitRate' => $targetBitRate,
						'sizeBytes' => $targetSizeBytes,
						'width' => $targetWidth,
						'height' => $targetHeight
					]
				]);

			}

		}

		AjaxResponse::returnError("Error encoding video.", debugEnabled() ? [
			'cmd' => $cmd,
			'execResult' => $execResult,
			'execOutput' => $execOutput,
			'ffprobeResult' => $ffprobeResult
		] : null);

		break;

	default:

		/*
		print_r([
			'upload_max_filesize' => ini_get('upload_max_filesize'),
			'post_max_size' => ini_get('post_max_size'),
			'$_POST' => $_POST,
			'$_FILES' => $_FILES
		]);
		*/

		AjaxResponse::criticalDie("Invalid action.");

}
