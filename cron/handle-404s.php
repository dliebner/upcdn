<?php

define('IN_SCRIPT', 1);

$root_path = '/home/bgcdn/';

require($root_path . 'common.php');

$db = db();

$redis = new Redis();
$redis->pconnect('127.0.0.1');

$start = time();

// Run every second for 60 seconds
set_time_limit(60 * 2);
while( time() - 60 < $start ) {

	start_timer('secondTimer');

	// Redis: Get and delete current 404 uris
	$ret = $redis->multi()
		->hGetAll('bgcdn:404_uris')
		->del('bgcdn:404_uris')
		->exec();

	if( $ret && ($_404uris = $ret[0]) ) {

		$missingTailpaths = [];
		foreach( array_keys($_404uris) as $path ) {

			// Skip potentially malicious paths
			if( strpos($path, '../') !== false || strpos($path, '://') !== false ) continue;

			/**
			 * 	Implement valid matching patterns
			 * 	Build list of URIs/paths to send to File Oracle
			 * 	Query File Oracle for file details
			 * 	Retrieve files from other client servers / B2
			 * 	Do any further processing as needed (such as unzipping)
			 */

			/*/ Dumb, all missing-paths version
			if( preg_match('@^/' . preg_quote(CDNClient::DIR_VIDEO) . '@', $path) ) {

				$relpath = substr($path, strlen('/' . CDNClient::DIR_VIDEO));

				if( !file_exists($root_path . CDNClient::DIR_WWW . CDNClient::DIR_VIDEO . $relpath) ) {

					$missingRelpaths[] = $relpath;

				}

			}
			*/

			// Smart, folder-structure-aware pattern-matching version
			$basePattern = '@^/' . preg_quote(CDNClient::DIR_VIDEO, '@');
			$endQuote = '@';

			$mp4Pattern = $basePattern . '[\w/]+/((([^.]+)_(\d+)x(\d+)(_na)?)\.mp4)$' . $endQuote;
			$hlsPattern = $basePattern . '[\w/]+/((([^.]+)_(\d+)x(\d+)(_na)?)/index\.m3u8)$' . $endQuote;

			if( preg_match($mp4Pattern, $path, $matches) ) {

				$type = 'mp4';
				$tailPath = $matches[1];
				$versionFilename = $matches[2];
				$srcFilename = $matches[3];
				$width = $matches[4];
				$height = $matches[5];
				$hasAudio = !$matches[6];

				if( !file_exists(VideoPath::mp4LocalPath($versionFilename)) ) {
	
					$missingTailpaths[] = $tailPath;
	
				}

			} else if( preg_match($hlsPattern, $path, $matches) ) {

				$type = 'hls';
				$tailPath = $matches[1];
				$versionFilename = $matches[2];
				$srcFilename = $matches[3];
				$width = $matches[4];
				$height = $matches[5];
				$hasAudio = !$matches[6];

				if( !file_exists(VideoPath::hlsIndexLocalPath($versionFilename)) ) {
	
					$missingTailpaths[] = $tailPath;
	
				}

			}

		}

		print_r($missingTailpaths);

		if( $missingTailpaths ) {

			// Query File Oracle (hub) for file details
			CDNClient::postToHub(CDNClient::HUB_ACTION_FILE_ORACLE_MISSING_PATHS, [
				'missingTailpaths' => $missingTailpaths
			], [
				'success' => function($hubResponse) {

					if( !isset($hubResponse->downloadVersions) ) throw new Exception("Missing downloadVersions in hub response " . print_r($hubResponse, 1));

					if( $downloadVersions = $hubResponse->downloadVersions ) {

						$downloadVersions = CDNTools::objectToArrayRecursive($downloadVersions);

						print_r($downloadVersions);

						$guzzleClient = new \GuzzleHttp\Client();

						$b2Client = new \dliebner\B2\Client(Config::get('b2_master_key_id'), [
							'keyId' => Config::get('b2_application_key_id'), // optional if you want to use master key (account Id)
							'applicationKey' => Config::get('b2_application_key'),
						]);
						$b2Client->version = 2; // By default will use version 1

						$missingFileDownloader = new MissingFileDownloader($guzzleClient, $b2Client, 10);

						foreach( $downloadVersions as $version ) {

							$versionFilename = $version['versionFilename'];

							$transcodingServerUrl = null;

							if( $version['transcodedByHostname'] && $version['transcodedByHostname'] !== Config::get('hostname') ) {

								$transcodingServerUrlBase = 'http://' . $version['transcodedByHostname'] . '/';

								switch( $version['type'] ) {

									case 'mp4':

										$transcodingServerUrl = $transcodingServerUrlBase . VideoPath::mp4UriPath($versionFilename);

										break;

									case 'hls':

										$transcodingServerUrl = $transcodingServerUrlBase . VideoPath::hlsZipUriPath($versionFilename);

										break;

								}

							}

							$missingFileDownloader->addFileToDownload(
								new MissingFile(
									($isHls = $version['type'] === 'hls') ? VideoPath::hlsZipLocalPath($versionFilename) : VideoPath::mp4LocalPath($versionFilename),
									$isHls,
									VideoPath::getVersionCloudPath($versionFilename, $version['type']),
									$transcodingServerUrl
								)
							);

						}

						$missingFileDownloader->doDownload();

					}
			
				}
			]);

		}

	}

	$timeElapsed = stop_timer('secondTimer');
	$waitSeconds = max(0, 1 - $timeElapsed);
	//echo "wait $waitSeconds\n";
	usleep($waitSeconds * 1000000);

}
