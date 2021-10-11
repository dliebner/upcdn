<?php

/*

	mysql.php
	
	MySQL Class

									*/

if( !defined("SQL_LAYER") ) {

	define("SQL_LAYER","mysql");
	define('MYSQL_DEFAULT_TRANSACTION_ISOLATION_LEVEL', 'REPEATABLE-READ');

	/*
	if( defined("DEBUG") && DEBUG ) {

		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

	} else {

		mysqli_report(MYSQLI_REPORT_ERROR);

	}
	*/

	class sql_db {

		public $db_connect_id;
		public $query_result;
		public $num_queries = 0;

		public $persistency;
		public $user;
		protected $password;
		public $server;
		public $dbname;

		public $mi;

		//
		// Constructor
		//
		public function __construct($sqlserver, $sqluser, $sqlpassword, $database, $persistency = false) {

			$this->persistency = $persistency;
			$this->user = $sqluser;
			$this->password = $sqlpassword;
			$this->server = $sqlserver;
			$this->dbname = $database;

			$this->mi = new mysqli(($this->persistency ? 'p:' : '') . $this->server, $this->user, $this->password, $this->dbname);

			if( !$this->mi->connect_errno ) {

				$this->db_connect_id = $this->mi->thread_id;

			}

		}

		//
		// Other base methods
		//
		public function sql_close() {

			if( $this->mi->thread_id ) {

				unset($this->db_connect_id);

				return $this->mi->close();

			} else {

				return false;

			}

		}

		public function sql_escape_string( $string ) {

			return $this->mi->real_escape_string( $string );

		}

		public function escape( $string ) {

			return $this->mi->real_escape_string( $string );

		}

		//
		// Base query method
		//
		public function sql_query($query = "", $deadlockRetryCount = 0) {

			// Remove any pre-existing queries
			unset($this->query_result);

			if( $query != "" ) {

				$this->num_queries++;

				$this->query_result = $this->mi->query($query);
				
			}

			if( $this->query_result ) {
			
				return $this->query_result;

			} else {

				if( $deadlockRetryCount <= 3 && in_array($this->mi->errno, [1213,1062]) ) {

					// Retry up to 3 times
					usleep(1000 * mt_rand(0, 80));

					return $this->sql_query($query, $deadlockRetryCount + 1);

				}

			}

		}

		//
		// Other query methods
		//

		public function sql_numrows($result = null) {

			if( !$result ) $result = $this->query_result;

			if( $result ) {

				/** @var mysqli_result $result */
				return $result->num_rows;

			} else {

				return false;

			}

		}

		public function sql_affectedrows() {

			return $this->mi->affected_rows;

		}

		public function sql_numfields($result = null) {

			if( !$result ) $result = $this->query_result;

			if( $result ) {

				/** @var mysqli_result $result */
				return $result->field_count;

			} else {

				return false;

			}

		}

		public function mysqli_field_properties($offset, $result = null) {

			if( !$result ) $result = $this->query_result;

			if( $result ) {

				/** @var mysqli_result $result */
				$properties = $result->fetch_field_direct($offset);

				return $properties;

			} else {

				return false;

			}


		}

		public function sql_fieldname($offset, $result = null) {

			if( $properties = $this->mysqli_field_properties($offset, $result) ) {

				return is_object($properties) ? $properties->name : null;

			} else {

				return $properties;

			}

		}

		public function sql_fieldtype($offset, $result = null) {

			if( $properties = $this->mysqli_field_properties($offset, $result) ) {

				return is_object($properties) ? $properties->type : null;

			} else {

				return $properties;

			}

		}

		public function sql_fetchrow($result = null, $type = 'assoc') {
			
			if( !$result ) $result = $this->query_result;

			if( $result ) {

				/** @var mysqli_result $result */

				switch( $type ) {

					case 'assoc':
						return $result->fetch_assoc();
						break;

					case 'row':
						return $result->fetch_row();
						break;

					default:
						return $result->fetch_array();
				}

			} else {

				return false;

			}

		}

		public function sql_fetchrowset($result = null, $type = 'assoc') {
			
			if( !$result ) $result = $this->query_result;

			if( $result ) {

				switch( $type ) {

					case 'assoc':  $mode = MYSQLI_ASSOC; break;
					case 'row':  $mode = MYSQLI_NUM; break;
					default:  $mode = MYSQLI_BOTH;

				}

				/** @var mysqli_result $result */
				return $result->fetch_all($mode);

			} else {

				return false;

			}

		}

		public function sql_rowseek($rownum, $result = null) {
			
			if( !$result ) $result = $this->query_result;

			if( $result ) {

				/** @var mysqli_result $result */
				return $result->data_seek($rownum);

			} else {

				return false;

			}

		}

		public function sql_nextid() {

			return $this->mi->insert_id;

		}

		public function sql_freeresult($result = null) {

			if( !$result ) $result = $this->query_result;

			if( $result ) {

				/** @var mysqli_result $result */
				$result->free_result();

				return true;

			} else {

				return false;

			}

		}

		public function sql_error() {

			return [
				'message' => $this->mi->error,
				'code' => $this->mi->errno
			];

		}

		public function sql_set_charset($charset) {
			
			return $this->mi->set_charset($charset);

		}

	}

}
