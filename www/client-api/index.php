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
		$_FILES['image']['tmp_name'];

		// In-progress
		AjaxResponse::returnSuccess([
			'files' => $_FILES,
			'hubResponse' => $responseData
		]);

		break;

	default:

		AjaxResponse::criticalDie("Invalid action.");

}
