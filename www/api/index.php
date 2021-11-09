<?php

define('IN_SCRIPT', 1);

$root_path = './../../';

use dliebner\B2\Client;

include_once( $root_path . 'common.php' );
require_once( $root_path . 'includes/JSONEncrypt.php');

/**
 * Client receiving from Hub
 */

 $secretKey = Config::get('secret_key');

// Attempt to decode the parcel
if( !$payload = JSONEncrypt::decode(postdata_to_original($_POST['parcel']), $secretKey ?: 'init') ) AjaxResponse::criticalDie('Invalid parcel.');

if( !$payload->action ) AjaxResponse::criticalDie('Missing action.');
$params = $payload->params;

if( !$secretKey ) {
		
	// Allow init only
	if( $payload->action == CDNClient::CLIENT_ACTION_INIT_SERVER ) {

		// Require HTTPS
		if( $_SERVER['HTTPS'] !== 'on' ) {
	
			AjaxResponse::criticalDie("Attempted to init insecurely. Choose a new secretKey and try again over HTTPS.");
	
		}

		$newConfig = (array)$params->updateConfig;

		if( !$newSecretKey = $newConfig['secret_key'] ) {

			AjaxResponse::criticalDie("No secret key passed.");

		}

		if( !$newServerId = $newConfig['server_id'] ) {

			AjaxResponse::criticalDie("No server ID passed.");

		}

		if( !$newHubApiUrl = $newConfig['hub_api_url'] ) {

			AjaxResponse::criticalDie("No hub API URL passed.");

		}

		$success = false;

		CDNClient::postToHub(CDNClient::HUB_ACTION_VALIDATE_SECRET_KEY, [], [
			'serverId' => $newServerId,
			'secretKey' => $newSecretKey,
			'hubApiUrl' => $newHubApiUrl,
			'success' => function() use (&$success) {

				$success = true;

			}
		]);

		if( $success ) {

			Config::setMulti($newConfig);

			AjaxResponse::returnSuccess();

		} else {

			AjaxResponse::criticalDie("Error validating secret key.");

		}

	} else {

		AjaxResponse::criticalDie('Invalid parcel.');

	}

}

switch( $payload->action ) {

	case CDNClient::CLIENT_ACTION_VALIDATE_SECRET_KEY:

		AjaxResponse::returnSuccess();

		break;

	case CDNClient::CLIENT_ACTION_SYNC_CLIENT_DATA:

		AjaxResponse::returnSuccess([
			'clientServerStatus' => ServerStatus::getAll(),
		]);

		break;

	case CDNClient::CLIENT_ACTION_CREATE_VIDEO_VERSION:

		if( !$sourceFilename = $params->sourceFilename ) AjaxResponse::criticalDie("Missing sourceFilename");
		if( !isset($params->sourceIsNew) ) AjaxResponse::criticalDie("Missing sourceIsNew");
		$sourceIsNew = $params->sourceIsNew;
		if( !$originalExtension = $params->originalExtension ) AjaxResponse::criticalDie("Missing originalExtension");
		if( !$fileSizeBytes = $params->fileSizeBytes ) AjaxResponse::criticalDie("Missing fileSizeBytes");
		if( !$maxSizeBytes = $params->maxSizeBytes ) AjaxResponse::criticalDie("Missing maxSizeBytes");
		if( !$duration = $params->duration ) AjaxResponse::criticalDie("Missing duration");
		if( !$versionFilename = $params->versionFilename ) AjaxResponse::criticalDie("Missing versionFilename");
		if( !$versionWidth = $params->versionWidth ) AjaxResponse::criticalDie("Missing versionWidth");
		if( !$versionHeight = $params->versionHeight ) AjaxResponse::criticalDie("Missing versionHeight");
		if( !$targetBitRate = $params->bitRate ) AjaxResponse::criticalDie("Missing bitRate");
		if( !$hlsByteSizeThreshold = $params->hlsByteSizeThreshold ) AjaxResponse::criticalDie("Missing hlsByteSizeThreshold");
		if( !$sourceFfprobeJson = $params->sourceFfprobeJson ) AjaxResponse::criticalDie("Missing sourceFfprobeJson");
		if( !isset($params->mute) ) AjaxResponse::criticalDie("Missing mute");
		$mute = $params->mute;

		if( !$probeResult = new FFProbeResult($sourceFfprobeJson) ) AjaxResponse::criticalDie("Error reading source ffprobe json");

		CDNTools::getEncodingSettings(
			$probeResult, $fileSizeBytes, $maxSizeBytes, $versionWidth, $versionHeight, $targetBitRate, $hlsByteSizeThreshold,
			$constrainWidth, $constrainHeight, $passThroughVideo, $saveAsHls
		);

		// Start new job
		$tcJob = TranscodingJob::create($sourceFilename, $sourceIsNew, $originalExtension, $fileSizeBytes, $duration, $versionFilename, $versionWidth, $versionHeight, new TranscodingJobSettings(
			$targetBitRate,
			$constrainWidth,
			$constrainHeight,
			$passThroughVideo,
			$saveAsHls,
			null,
			$mute
		));

		if( $tcJob->sourceVideoExistsOnDisk() ) {

			// Start transcoding now
			$tcJob->startTranscode();

			AjaxResponse::returnSuccess([
				'progressToken' => $tcJob->progressToken
			]);

		} else {

			// Get source video from cloud
			$tcJob->createInProgressDir();

			AjaxResponse::returnSuccessPersist([
				'progressToken' => $tcJob->progressToken
			]);

			// Start transcoding in the background
			$client = new Client(Config::get('b2_master_key_id'), [
				'keyId' => Config::get('b2_application_key_id'), // optional if you want to use master key (account Id)
				'applicationKey' => Config::get('b2_application_key'),
			]);
			$client->version = 2; // By default will use version 1

			if( !$client->download([
				'BucketName' => Config::get('b2_bucket_name'),
				'FileName' => $tcJob->getSrcCloudPath(),
				'SaveAs' => $tcJob->inProgressPath()
			])) {

				// fuck TODO: handle errors

			};

		}

		break;

	default:

		AjaxResponse::criticalDie('Invalid action: ' . $payload->action);

		break;

}