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

		if( !$multi = $params->multi ) {

			$multi = [$params];

		}

		$progressTokens = [];

		foreach( $multi as $multiParams ) {

			if( !$sourceFilename = $multiParams->sourceFilename ) AjaxResponse::criticalDie("Missing sourceFilename");
			if( !isset($multiParams->sourceIsNew) ) AjaxResponse::criticalDie("Missing sourceIsNew");
			$sourceIsNew = $multiParams->sourceIsNew;
			if( !$originalExtension = $multiParams->originalExtension ) AjaxResponse::criticalDie("Missing originalExtension");
			if( !$fileSizeBytes = $multiParams->fileSizeBytes ) AjaxResponse::criticalDie("Missing fileSizeBytes");
			if( !$maxSizeBytes = $multiParams->maxSizeBytes ) AjaxResponse::criticalDie("Missing maxSizeBytes");
			if( !$duration = $multiParams->duration ) AjaxResponse::criticalDie("Missing duration");
			if( !$versionFilename = $multiParams->versionFilename ) AjaxResponse::criticalDie("Missing versionFilename");
			if( !$versionWidth = $multiParams->versionWidth ) AjaxResponse::criticalDie("Missing versionWidth");
			if( !$versionHeight = $multiParams->versionHeight ) AjaxResponse::criticalDie("Missing versionHeight");
			if( !$targetBitRate = $multiParams->bitRate ) AjaxResponse::criticalDie("Missing bitRate");
			if( !$hlsByteSizeThreshold = $multiParams->hlsByteSizeThreshold ) AjaxResponse::criticalDie("Missing hlsByteSizeThreshold");
			if( !$sourceFfprobeJson = $multiParams->sourceFfprobeJson ) AjaxResponse::criticalDie("Missing sourceFfprobeJson");
			if( !isset($multiParams->mute) ) AjaxResponse::criticalDie("Missing mute");
			$mute = $multiParams->mute;

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

			if( !$tcJob->sourceVideoExistsOnDisk() ) {

				$tcJob->createInProgressDir();

				// Check if source exists from other jobs
				foreach( TranscodingJob::getAllBySrcFilename($tcJob->srcFilename) as $otherJob ) {

					if( $otherJob->id === $tcJob->id ) continue;

					if( $otherJob->sourceVideoExistsOnDisk() ) {

						// Copy source video to new job's inProgressPath
						copy($otherJob->inProgressPath(), $tcJob->inProgressPath());
						
						break;

					}

				}

			}

			// Get source video from cloud
			if( $tcJob->sourceVideoExistsOnDisk() ) {

				// Start transcoding now
				$tcJob->setTranscodeReady();
				$tcJob->startTranscode();

			} else {
				
				$tcJob->queueSourceDownload();

			}

			$progressTokens[] = $tcJob->progressToken;

		}

		AjaxResponse::returnSuccess([
			'progressTokens' => $progressTokens
		]);

		break;

	default:

		AjaxResponse::criticalDie('Invalid action: ' . $payload->action);

		break;

}