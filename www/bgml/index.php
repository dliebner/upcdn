<?php

define('IN_SCRIPT', 1);

$root_path = './../../';

include_once( $root_path . 'common.php' );
require_once( $root_path . 'includes/JSONEncrypt.php');

function default_exception_handler($e) {

	Logger::logEvent("api exception", [
		'email' => true,
		'exception' => $e
	]);
	
	$eClass = get_class($e);
	
	if( in_array($eClass, array('QueryException','GeneralException','SilentAjaxException','GeneralExceptionWithData')) ) {
	
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

			AjaxResponse::returnError($e->getMessage(), debugEnabled() ? $e->data : null, $options);
			
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
 * Client receiving from Hub
 */

 $secretKey = Config::get('secret_key');

// Attempt to decode the parcel
if( !$payload = JSONEncrypt::decode(postdata_to_original($_POST['parcel']), $secretKey ?: 'init') ) AjaxResponse::criticalDie('Invalid parcel.');

if( !$payload->action ) AjaxResponse::criticalDie('Missing action.');
$params = $payload->params;

switch( $payload->action ) {

	case 'generate_win_probability_table':

		if( !$params->bidDataCsv ) AjaxResponse::criticalDie('Missing bidDataCsv');

		// Save bidDataCsv to file
		while( ($bidDataCsvPath = '/home/bgcdn/bgml/tmp/bid_data_' . CDNTools::getRandomBase64(10) . '.csv') && file_exists($bidDataCsvPath) );
		file_put_contents($bidDataCsvPath, $params->bidDataCsv);

		// Exec win-prob-model.py with params
		$arg_adUnitId = escapeshellarg($params->adUnitId);
		$arg_bidDataCsv = escapeshellarg($bidDataCsvPath);
		$cmd = escapeshellcmd(
			"python3.9 /home/bgcdn/bgml/win-prob-model.py --ad_unit_id $arg_adUnitId --bid_data_csv $arg_bidDataCsv --other_cat_features geo_id"
		);

		exec($cmd, $execOutput, $execResult);

		// Remove bidDataCsv file
		unlink($bidDataCsvPath);

		if( $execResult ) {

			AjaxResponse::criticalDie('Error code ' . $execResult);

		}

		// Final line of output is generated CSV path
		$mlGeneratedCsv = $execOutput[count($execOutput) - 1];

		if( !file_exists($mlGeneratedCsv) ) {

			AjaxResponse::criticalDie("Couldn't find generated csv at " . $mlGeneratedCsv);

		}

		$mlGeneratedCsvContents = file_get_contents($mlGeneratedCsv);

		// Remove generated CSV
		unlink($mlGeneratedCsv);

		AjaxResponse::returnSuccess([
			'mlGeneratedCsvContents' => $mlGeneratedCsvContents
		]);

		break;

	default:

		AjaxResponse::criticalDie('Invalid action: ' . $payload->action);

		break;

}
