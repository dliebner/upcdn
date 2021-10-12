<?php

if( !defined('IN_SCRIPT') ) die( "Hacking attempt" );

define('MYSQL_DATETIME_FORMAT', "Y-m-d H:i:s");
define('MYSQL_DATE_FORMAT', "Y-m-d");

function postdata_to_original($postdata) {
	
	return $postdata;
	
}

function mysql_escape_mimic($inp) {

    if( is_array($inp) ) {

        return array_map(__METHOD__, $inp);

	}

    if( !empty($inp) && is_string($inp) ) {
		
        return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);

    }

    return $inp;

}

function original_to_query($original) {

	global $db;

	if( $db && $db->db_connect_id ) {
	
		return $db->sql_escape_string($original);

	} else {

		return mysql_escape_mimic($original);

	}
	
}

function postdata_to_query($postdata) {
	
	return original_to_query(postdata_to_original($postdata));
	
}

/**
 * Returns a MySQL DATE string (Y-m-d)
 */
function date_to_query(DateTime $dateTime) {
	
	return original_to_query($dateTime->format(MYSQL_DATE_FORMAT));
	
}

/**
 * Returns a MySQL DATETIME string (Y-m-d H:i:s)
 */
function datetime_to_query(DateTime $dateTime) {
	
	return original_to_query($dateTime->format(MYSQL_DATETIME_FORMAT));
	
}

function myspecialchars($string) {
	
	return htmlspecialchars($string, ENT_COMPAT | ENT_XHTML, "UTF-8");
	
}

function h($string) {
	
	return nl2br(myspecialchars($string));
	
}

function br2nl($string){
	
	$noNewLines = preg_replace( '@[\r\n]+@i', "", $string );
	return preg_replace( '@<br\s*/?>@i', "\n", $noNewLines ); 

}

function simple_microtime() {
	
	$parts = explode(' ', microtime());
	
	return $parts[1] . substr($parts[0], 1);
	
}

function start_timer($var = 'default') {
	
	$tstart = simple_microtime();
	
	$GLOBALS['timers'][$var] = $tstart;
	
	return true;
	
}

function stop_timer($var = 'default') {
	
	$tstart = $GLOBALS['timers'][$var];
    
    $tend = simple_microtime();
    
	//Calculate the difference
	$totaltime = ($tend - $tstart);
	
	unset($GLOBALS['timers'][$var]);
	
	return $totaltime;
	
}

function gmp_convert($num, $base_a, $base_b) {
	
	return @gmp_strval( gmp_init($num, $base_a), $base_b );
	
}

function mkdir_recursive($pathname, $mode=0755)
{
    is_dir(dirname($pathname)) || mkdir_recursive(dirname($pathname), $mode);
    if (is_dir($pathname)) {
	    return true;
    } else if (mkdir($pathname, $mode) && chmod($pathname, $mode)) {
	    return true;
    } else {
	    return false;
    }
}

function getimageinfo($filelocation, $maxDlSize = 5242880) {
	
	global $root_path;
		
	if ( !is_file($filelocation) || (!$properties = @getimagesize($filelocation)) ) {

		return false;
		
	}
	
	$properties['width'] = $properties[0];
	$properties['height'] = $properties[1];
		
	// Mime Type
	// 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM
	switch ($properties[2]) {
		
		case 1:
			
			$mimetype = 'gif';
			break;
			
		case 2:
			
			$mimetype = 'jpg';
			break;
			
		case 3:
			
			$mimetype = 'png';
			break;
			
		case 4:
		case 13:
			
			$mimetype = 'swf';
			break;
			
		case 6:
			
			$mimetype = 'bmp';
			break;
			
	}	
	
	$properties['mime'] = $mimetype;
	
	return $properties;
	
}

function isAssoc(array $arr) {
		
	if (array() === $arr) return false;
	
	return array_keys($arr) !== range(0, count($arr) - 1);
	
}

/**
 * @param array $options sortBy, flatReduce, skipObjectsWithUnmatchedGroupByKeys
 */
function groupObjectsByProperties(array &$arr, array $groupBy, array $options = []) {
	
	$itemsByGroup = array();
	$sortBy = $options['sortBy'];
	$boolFlatReduce = $options['flatReduce'];

	if( $options['byReference'] ) {

		$arrayRef = &$arr;

	} else {

		$arrayRef = $arr;

	}
	
	foreach( $arrayRef as &$item ) {

		$assocItem = is_object($item) ? (array)$item : $item;
		
		$arrayLevel = &$itemsByGroup;
		
		for( $i = 0; $i < count($groupBy); $i++ ) {
		
			if( $groupBy[$i] instanceof Closure ) {
				$groupByVal = $groupBy[$i]($assocItem);
			} else if( !isset($assocItem[$groupBy[$i]]) ) {
				if( $options['skipObjectsWithUnmatchedGroupByKeys'] ) {
					continue 2;
				} else {
					throw new Exception("groupObjectsByProperties: tried to group on non-existing property: " . $groupBy[$i]);
				}
			} else {
				$groupByVal = $assocItem[$groupBy[$i]];
			}
			
			if( !isset($arrayLevel[$groupByVal]) ) {
				
				if( $i < count($groupBy) - 1 ) {
					
					$arrayLevel[$groupByVal] = [];
					
				} else {

					if( $boolFlatReduce ) {
						
						$arrayLevel[$groupByVal] = &$item;

					} else {
				
						$arrayLevel[$groupByVal] = [];

					}
					
				}
				
			}
			
			if( !($boolFlatReduce && $i >= count($groupBy) - 1) ) $arrayLevel = &$arrayLevel[$groupByVal];
			
		}
		
		if( !$boolFlatReduce ) {
		
			$arrayLevel[] = &$item;
			
		}
		
	}
	unset($item);
	
	if( $sortBy ) { // TODO: not sure if this is by reference or not
		
		$nextLevel = function($itemsByGroup, $depth, $maxDepth) use (&$nextLevel, $sortBy, $options) {
			
			if( $depth < $maxDepth ) {
				
				$depthOrderedItemsByGroup = [];
				
				foreach( $itemsByGroup as $key => $val ) {
					
					$depthOrderedItemsByGroup[$key] = $nextLevel($val, $depth + 1, $maxDepth);
					
				}
				
			}
			
			$itemsByGroup = $depthOrderedItemsByGroup ?: $itemsByGroup;
			
			if( $sortBy[$depth] ) {
				
				if( !isAssoc($itemsByGroup) ) {
					
					if( $sortBy[$depth] instanceof Closure ) {
						
						$sortFn = $sortBy[$depth];
						
					} else {
						
						$sortDir = 'asc';
						$inverse = 1;
						
						if( is_array($sortBy[$depth]) ) {
							
							$sortKey = $sortBy[$depth][0];
							$sortDir = $sortBy[$depth][1];
							
						} else {
							
							$sortKey = $sortBy[$depth];
							
						}
						
						if( $sortDir === 'desc' ) $inverse = -1;
						
						$sortFn = function($a, $b) use ($inverse, $sortKey) {
							
							$a = $a[$sortKey];
							$b = $b[$sortKey];
							
							$result = $inverse * strnatcasecmp($a, $b);
							
							return $result;
							
						};
						
					}
					
					$copy = $itemsByGroup;
					usort($copy, $sortFn);
					
					return $copy;
					
				} else {
					
					$orderedItemsByGroup = [];
					
					if( $sortBy[$depth] instanceof Closure ) {
						
						$sortFn = $sortBy[$depth];
						
					} else {
						
						$inverse = $sortBy[$depth] === 'desc' ? -1 : 1;
						
						$sortFn = function($a, $b) use ($inverse) {
							
							return $inverse * strnatcasecmp($a, $b);
							
						};
						
					}
					
					$sortedObjectKeys = array_keys($itemsByGroup);
					usort($sortedObjectKeys, $sortFn);
					
					foreach( $sortedObjectKeys as $i => $key ) {
						if( $options['preserveKeys'] ) {
							$orderedItemsByGroup[$key] = $itemsByGroup[$key];
						} else {
							$orderedItemsByGroup[] = $itemsByGroup[$key];
						}
					}
						
					return $orderedItemsByGroup;
						
				}
				
			}
			
			return $itemsByGroup;
			
		};
		
		$sortedItemsByGroup = $nextLevel($itemsByGroup, 0, count($sortBy) - 1);
		
	}
	
	return $sortedItemsByGroup ?: $itemsByGroup;
	
}

class QueryException extends Exception {
	
	public $sql;
	public $sql_error;
	
	public function __construct($message, $sql, $sql_db = null) {

		if( !$sql_db ) {

			global $db;
			$sql_db = $db;

		}
		
		$this->sql = $sql;
		$this->sql_error = $sql_db->sql_error();
		
		parent::__construct($message, 0, null);
		
	}
	
}

class Config {
	
	protected static $data;
	
	protected static function loadConfig($forceRefresh = false) {
		
		if( $forceRefresh || !isset(self::$data) ) {

			$db = db();
			
			$sql = "SELECT `property`, `value` FROM config";
			
			if( !$result = $db->sql_query($sql) ) {
				
				throw new QueryException('Could not select from config', $sql);
				
			}
			
			self::$data = array();
			while( $row = $db->sql_fetchrow($result) ) {
				
				self::$data[$row['property']] = $row['value'];
				
			}

		}
		
	}
	
	public static function get($property) {
		
		self::loadConfig();
		
		if( !self::is_set($property) ) {
			
			throw new Exception('Config: Tried to get nonexistant property: ' . $property);
			
		}
		
		return self::$data[$property];
		
	}
	
	public static function is_set($property) {

		self::loadConfig();
		
		return isset(self::$data) && isset(self::$data[$property]);
		
	}
	
	public static function set($property, $value) {
		
		self::loadConfig();
		
		self::$data[$property] = $value;
		
		$sql = "INSERT INTO config (`property`, `value`) VALUES
			('" . original_to_query($property) . "', '" . original_to_query($value) . "') as aux
			ON DUPLICATE KEY UPDATE
				`value` = aux.value";
		
		if( !db()->sql_query($sql) ) {
			
			throw new QueryException('Error insert/updating into config', $sql);
			
		}
		
	}
	
	public static function delete($property) {
		
		self::loadConfig();
		
		if( self::is_set($property) ) unset(self::$data[$property]);
		
		$sql = "DELETE FROM config
			WHERE `property` = '" . original_to_query($property) . "'";
		
		if( !db()->sql_query($sql) ) {
			
			throw new QueryException('Error deleting from config', $sql);
			
		}
		
	}
	
}

class ServerStatus {
	
	protected static $data;
	
	protected static function loadAll() {

		$db = db();
		
		$sql = "SELECT `property`, `value` FROM server_status";
		
		if( !$result = $db->sql_query($sql) ) {
			
			throw new QueryException('Could not select from server_status', $sql);
			
		}
		
		self::$data = array();
		while( $row = $db->sql_fetchrow($result) ) {
			
			self::$data[$row['property']] = json_decode($row['value'], true);
			
		}
		
	}
	
	public static function get($property, $forceRefresh = false) {
		
		if( $forceRefresh || !isset(self::$data) ) {
			
			self::loadAll();
			
		}
		
		if( !isset(self::$data[$property]) ) {
			
			throw new Exception('ServerStatus: Tried to get nonexistant property: ' . $property);
			
		}
		
		return self::$data[$property];
		
	}
	
	public static function set($property, $value) {
		
		$db = db();
		
		if( !isset(self::$data) ) {
			
			self::loadAll();
			
		}
		
		self::$data[$property] = $value;
		
		$sql = "INSERT INTO server_status (`property`, `value`) VALUES
			('" . original_to_query($property) . "', '" . original_to_query(json_encode($value)) . "') as aux
			ON DUPLICATE KEY UPDATE
				`value` = aux.value";
		
		if( !$db->sql_query($sql) ) {
			
			throw new QueryException('Error insert/updating into server_status', $sql);
			
		}
		
	}
	
}
