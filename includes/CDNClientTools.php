<?php

// TODO: Rename to CDNClientTools.php

if( !defined('IN_SCRIPT') ) die( "Hacking attempt" );

class CDNClient {

	/**
	 * Directories:
	 * 	- Uploads in progress (PHP temp dir)
	 * 	- Transcoding in progress
	 * 		Source file
	 * 		Output file or path (ie HLS)
	 */
	const DIR_TRANSCODE_IN_PROGRESS = 'transcoding/';
	const DIR_TRANSCODE_OUTPUT = 'out/';
	const DIR_WWW = 'www/';

	const HUB_ACTION_VALIDATE_SECRET_KEY = 'validateSecretKey';
	const HUB_ACTION_SYNC_CLIENT_DATA = 'syncClientData';
	const HUB_ACTION_VALIDATE_CDN_TOKEN = 'validateCdnToken';

	const CLIENT_ACTION_INIT_SERVER = 'initServer';
	const CLIENT_ACTION_VALIDATE_SECRET_KEY = 'validateSecretKey';
	const CLIENT_ACTION_SYNC_CLIENT_DATA = 'syncClientData';

	public static function postToHub( $action, $params = array(), $options = array() ) {

		global $root_path;

		$serverId = Config::get('server_id') ?: $options['serverId'];
		$secretKey = Config::get('secret_key') ?: $options['secretKey'];
		$hubApiUrl = Config::get('hub_api_url') ?: $options['hubApiUrl'];

		if( !$serverId ) throw new Exception('Server ID is not set.');
		if( !$secretKey ) throw new Exception('Secret key is not set.');
		if( !$hubApiUrl ) throw new Exception('Hub API URL is not set.');

		require_once($root_path. 'includes/JSONEncrypt.php');
		
		// Pack the parcel
		$parcel = array('action' => $action);
		if( is_array($params) && count($params) > 0 ) $parcel['params'] = $params;
		
		$curlParams = array(
			'id' => $serverId,
			'parcel' => JSONEncrypt::encode($parcel, $secretKey)
		);
		
		if( !$response = self::curlPost($hubApiUrl, $curlParams) ) {
			
			throw new Exception('Error when posting to the hub server: no response');
			
		}
			
		if( (!$parsedResponse = json_decode($response)) || !$parsedResponse->status ) {
			
			throw new Exception('Error when posting to the hub server: ' . $response);
			
		}
		
		switch( $parsedResponse->status ) {
			
			case 'success':
				
				if( is_callable($options['success']) ) {
					
					call_user_func($options['success'], $parsedResponse);
					
				}
				
				break;
				
			case 'critical':
				
				throw new Exception('Critical error returned from hub server: ' . $parsedResponse->message);
				
				break;
			
		}
		
	}

	public static function corsOriginAllowed($origin) {

		$corsOrigins = explode(",", Config::get('cors_origins'));

		return in_array($origin, $corsOrigins);

	}

	public static function validateCdnToken($cdnToken, $action, &$hubResponseDataArray = null, $ip = null, $userId = null) {

		$success = false;

		CDNClient::postToHub(CDNClient::HUB_ACTION_VALIDATE_CDN_TOKEN, [
			'tokenKey' => $cdnToken,
			'action' => $action,
			'ip' => $ip,
			'userId' => $userId
		],[
			'success' => function($response) use (&$success, &$hubResponseDataArray) {

				if( $response->data && $response->data->result ) $success = true;

				$hubResponseDataArray = (array)$response->data;

			}
		]);

		return $success;

	}

	public static function syncClientServerStatus() {

		self::postToHub(self::HUB_ACTION_SYNC_CLIENT_DATA, [
			'clientServerStatus' => ServerStatus::getAll(),
		]);

	}

	public static function syncClientServerConfig() {

		self::postToHub(self::HUB_ACTION_SYNC_CLIENT_DATA, [
			'clientServerConfig' => Config::getAll(),
		]);

	}

	protected static function curlPost($url, $params = array()) {
		
		//open connection
		$ch = curl_init();
		
		//set the url, number of POST vars, POST data
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// set the connect timeout
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		
		if( count($params) > 0 ) {
			
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			
		}
		
		//execute post
		$response = curl_exec($ch);
		
		if( $err = curl_error($ch) ) throw new Exception('cURL error: ' . $err);
		
		//close connection
		curl_close($ch);
		
		return $response;
		
	}

}

class CDNTools {

	public static function getPortSpeedBits() {

		$portSpeedTxt = strtolower(Config::get('port_speed', true));

		//				 1		 2		  3
		$pattern = '/^\s*(\d+)\s*([KMGT])?(bit|bps)?\s*$/i';

		if( !preg_match($pattern, $portSpeedTxt, $matches) ) throw new Exception("Error reading port speed from config");

		$unitConversion = [
			'K' => 1000,
			'M' => 1000000,
			'G' => 1000000000,
			'T' => 1000000000000
		];

		$bits = (int)$matches[1];

		if( !$matches[2] && !$matches[3] ) throw new Exception("Error reading port speed from config");

		if( $matches[2] ) {

			$bits *= $unitConversion[strtoupper($matches[2])];

		}

		return $bits;

	}

	public static function getMonthlyBandwidthUsedBytes() {

		$db = db();

		$sql = "SELECT bytes_out
			FROM bandwidth_logs
			WHERE month = LAST_DAY(NOW() - INTERVAL 1 MONTH) + INTERVAL 1 DAY";

		if( !$result = $db->sql_query($sql) ) {

			throw new QueryException("Error selecting", $sql);

		}

		return (int)$db->sql_fetchrow($result)['bytes_out'];

	}

	public static function getMonthlyBandwidthUsedPct() {

		$monthlyBandwidthAlloc = Config::get('monthly_bandwidth_alloc', true);
		$monthlyBandwidthAllocBytes = (int)str_replace('B', '', ByteUnits\parse($monthlyBandwidthAlloc)->format('B'));

		return self::getMonthlyBandwidthUsedBytes() / $monthlyBandwidthAllocBytes;

	}

	public static function getPctMonthPassed() {

		$firstOfTheMonth = (new DateTime('today'))->modify('first day of this month');
		$firstOfNextMonth = (new DateTime('today'))->modify('first day of next month');
		$fotmTs = $firstOfTheMonth->getTimestamp();
		$pctMonthPassed = (time() - $fotmTs) / ($firstOfNextMonth->getTimestamp() - $fotmTs);

		return $pctMonthPassed;

	}

	public static function getProjectedMonthlyBandwidthUsedPct() {

		return self::getMonthlyBandwidthUsedPct() / self::getPctMonthPassed();

	}
	
}

class CpuPercentCalculator {

	protected $statData1;
	protected $statData2;

	protected function getServerLoadLinuxData() {

		$cpuVals = null;

		if( $handle = fopen('/proc/stat', 'r') ) {

			while( ($line = fgets($handle)) !== false ) {

				// process the line read.
				if( ($trimLine = preg_replace('/^cpu\s+/i', '', $line, -1, $count)) && $count ) {

					// Total CPU, i.e.
					// cpu  1310702 610184 429957 435005796 24705 0 119391 0 0 0
					$cpuVals = preg_split('/\s+/', $trimLine, 5);
					array_pop($cpuVals);

					break;

				}

			}
			fclose($handle);

		}

		return $cpuVals;

	}

	// Returns server load in percent (just number, without percent sign)
	public function getCpuPercent($sleep = 1, $reuse = false) {

		if( is_readable('/proc/stat') ) {

			// Collect 2 samples - each with 1 second period
			// See: https://de.wikipedia.org/wiki/Load#Der_Load_Average_auf_Unix-Systemen
			$statData1 = $this->statData1 ?: ($this->statData1 = $this->getServerLoadLinuxData());

			sleep($sleep);

			$statData2 = $this->statData2 = $this->getServerLoadLinuxData();

			if( $statData1 && $statData2 ) {

				// Get difference
				$diff0 = $statData2[0] - $statData1[0];
				$diff1 = $statData2[1] - $statData1[1];
				$diff2 = $statData2[2] - $statData1[2];
				$diff3 = $statData2[3] - $statData1[3];

				// Sum up the 4 values for User, Nice, System and Idle and calculate
				// the percentage of idle time (which is part of the 4 values!)
				$cpuTime = $diff0 + $diff1 + $diff2 + $diff3;

				// Invert percentage to get CPU time, not idle time
				$pctCpu = 1 - ($diff3 / $cpuTime);

				if( $reuse ) {
					
					// Move $statData2 => $statData1
					$this->statData1 = $statData2;

				} else {

					unset($this->statData1);

				}

				return $pctCpu;

			}

		}

	}

}

class FFProbeResult_Stream {

	public $codecType;
	public $codecName;
	public $bitRate;
	public $duration;

	protected function __construct($obj) {

		$this->codecType = $obj['codec_type'];
		$this->codecName = $obj['codec_name'];

		$this->bitRate = (int)$obj['bit_rate'];
		$this->duration = (float)$obj['duration'];
		
	}

	public static function createFromJson($json) {

		if( is_string($json) ) $json = json_decode($json, true);
		if( is_object($json) ) $json = (array)$json;
		if( !is_array($json) ) throw new Exception("Error creating FFProbeResult_Stream from \$json");

		$codecType = $json['codec_type'];

		switch( $codecType ) {

			case 'video': return new FFProbeResult_VideoStream($json);
			case 'audio': return new FFProbeResult_AudioStream($json);

			default: throw new Exception("Unknown codec type");

		}

	}

}

class FFProbeResult_VideoStream extends FFProbeResult_Stream {

	public $width;
	public $height;
	public $sampleAspectRatioString;
	public $sampleAspectRatioFloat;
	public $displayAspectRatioString;
	public $displayAspectRatioFloat;

	protected function __construct($obj) {

		parent::__construct($obj);

		$this->width = (int)$obj['width'];
		$this->height = (int)$obj['height'];

		$this->sampleAspectRatioString = $sar = $obj['sample_aspect_ratio'];
		$sarParts = explode(':', $sar);
		$this->sampleAspectRatioFloat = $sarParts[0] / $sarParts[1];

		$this->displayAspectRatioString = $dar = $obj['display_aspect_ratio'];
		$darParts = explode(':', $dar);
		$this->displayAspectRatioFloat = $darParts[0] / $darParts[1];
		
	}

	public function displayWidth() {

		return $this->width;

	}

	public function displayHeight() {

		return round($this->width / $this->displayAspectRatioFloat);

	}

}

class FFProbeResult_AudioStream extends FFProbeResult_Stream {



}

class FFProbeResult {

	public $probeScore;
	public $duration;
	public $sizeBytes;
	public $bitRate;
	public $formats = [];

	public $videoStreams = [];
	public $audioStreams = [];

	public function __construct($json) {

		if( is_string($json) ) $json = json_decode($json, true);
		if( is_object($json) ) $json = (array)$json;
		if( !is_array($json) ) throw new Exception("Error constructing FFProbeResult from \$json");
		if( !$format = $json['format'] ) throw new Exception("Error reading format");
		if( !$streams = $json['streams'] ) throw new Exception("Error reading streams");

		$this->probeScore = (int)$format['probe_score'];
		$this->duration = (float)$format['duration'];
		$this->sizeBytes = (int)$format['size'];
		$this->bitRate = (int)$format['bit_rate'];
		$this->formats = explode(',', $format['format_name']);

		foreach( $streams as $streamObj ) {

			$stream = FFProbeResult_Stream::createFromJson($streamObj);

			if( $stream instanceof FFProbeResult_VideoStream ) {

				$this->videoStreams[] = $stream;

			} else if( $stream instanceof FFProbeResult_AudioStream ) {

				$this->audioStreams[] = $stream;

			}

		}
		
	}

}
