<?php

define('IN_SCRIPT', 1);

$root_path = './../../';

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

	default:

		AjaxResponse::criticalDie('Invalid action: ' . $payload->action);

		break;

}