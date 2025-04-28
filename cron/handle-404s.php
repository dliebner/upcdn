<?php

define('IN_SCRIPT', 1);

$root_path = '/home/upcdn/';

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
		->hGetAll('upcdn:404_uris')
		->del('upcdn:404_uris')
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

			$mp4Pattern = $basePattern . '[\w/]+/((([^.]+)_(\d+)(?:x(\d+))?(h)?(_na)?)\.mp4)$' . $endQuote;
			$hlsPattern = $basePattern . '[\w/]+/((([^.]+)_(\d+)(?:x(\d+))?(h)?(_na)?)/index\.m3u8)$' . $endQuote;
			$posterPattern = $basePattern . '[\w/]+/(([^.]+)_poster_(\d+)\.jpg)$' . $endQuote;

			if( preg_match($mp4Pattern, $path, $matches) ) {

				$type = 'mp4';
				$tailPath = $matches[1];
				$versionFilename = $matches[2];
				$srcFilename = $matches[3];
				$width = $matches[4];
				$height = $matches[5];
				$heightFlag = $matches[6];
				$hasAudio = !$matches[7];

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
				$heightFlag = $matches[6];
				$hasAudio = !$matches[7];

				if( !file_exists(VideoPath::hlsIndexLocalPath($versionFilename)) ) {
	
					$missingTailpaths[] = $tailPath;
	
				}

			} else if( preg_match($posterPattern, $path, $matches) ) {

				$type = 'poster';
				$tailPath = $matches[1];
				$srcFilename = $matches[2];
				$posterFrameIndex = $matches[3];

				if( !file_exists(VideoPath::posterLocalPath($srcFilename, $posterFrameIndex)) ) {

					$missingTailpaths[] = $tailPath;

				}

			}

		}

		if( $missingTailpaths ) {

			// Query File Oracle (hub) for file details
			CDNClient::postToHub(CDNClient::HUB_ACTION_FILE_ORACLE_MISSING_PATHS, [
				'missingTailpaths' => $missingTailpaths
			], [
				'success' => function($hubResponse) {

					$responseData = CDNTools::objectToArrayRecursive($hubResponse->data);

					if( !isset($responseData['downloadVersions']) ) throw new Exception("Missing downloadVersions in hub response");

					if( $downloadVersions = $responseData['downloadVersions'] ) {

						print_r(['downloadVersions' => $downloadVersions]);

						CDNClient::prepareDownloadVideoVersions($downloadVersions, $missingFileDownloader);

					}

					if( $downloadPosters = $responseData['downloadPosters'] ) {

						print_r(['downloadPosters' => $downloadPosters]);

						CDNClient::prepareDownloadPosters($downloadPosters, $missingFileDownloader);

					}

					if( $missingFileDownloader ) {

						$missingFileDownloader->doDownload();

						print_r([
							'downloadedFiles' => $missingFileDownloader->getAllDownloadedFiles(),
							'failedFiles' => $missingFileDownloader->getAllFailedFiles()
						]);

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
