<?php

define('IN_SCRIPT', 1);

$root_path = './../../';

include_once( $root_path . 'common.php' );
require_once( $root_path . 'includes/JSONEncrypt.php');

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

		// Validate video file
		$maxSizeBytes = $responseData['maxSizeBytes'];
		$maxDuration = $responseData['maxDuration'];

		$_FILES['image']['name'];
		$tmpFile = $_FILES['image']['tmp_name'];

		$fileSizeBytes = filesize($tmpFile);

		if( $fileSizeBytes > $maxSizeBytes ) {

			AjaxResponse::returnError("Max file size: " . humanFilesize($maxSizeBytes));

		}

		// ffprobe (TODO: functionize)
		$dir = escapeshellarg(dirname($tmpFile));
		$filename = escapeshellarg(basename($tmpFile));
		$cmd = escapeshellcmd(
			"sudo /home/bgcdn/scripts/docker-ffprobe.sh -d $pwd -f $filename"
		);

		exec($cmd, $execOutput, $execResult);

		if( $execResult === 0 || $execResult === 1 ) {

			if( $ffprobeResult = json_decode(implode(PHP_EOL, $execOutput), true) ) {

				unset($execOutput);

				// Check probe result
				$probeResult = new FFProbeResult($ffprobeResult);

				if( !$videoStream = $probeResult->videoStreams[0] ) {

					AjaxResponse::returnError("Invalid video file.");

				}

				if( $probeResult->duration > $maxDuration && $fileSizeBytes > $maxSizeBytes ) {

					AjaxResponse::returnError("Video must be under " . floor($maxDuration) . " seconds long or " . humanFilesize($maxSizeBytes) . ".");

				}

				// Encode video file
				$targetBitRate = $responseData['bitRate'];
				$targetSize = ceil($targetBitRate * $probeResult->duration);
				$versionWidth = $responseData['versionWidth'];
				$versionHeight = $responseData['versionHeight'];

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
						'size' => $targetSize,
						'width' => $versionWidth,
						'height' => $versionHeight
					]
				]);

			}

		}

		AjaxResponse::criticalDie("Error encoding video.", in_array($_SERVER['REMOTE_ADDR'], explode(",", Config::get('debug_ips'))) ? [
			'execResult' => $execResult,
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
