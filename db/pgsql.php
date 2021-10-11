<?php

class pgsql_db {

	const BEGIN_TRANSACTION = 1;
	const END_TRANSACTION = 2;

	public $db_connect_id;
	public $query_result;
	public $in_transaction = 0;
	public $row = array();
	public $rowset = array();
	public $rownum = array();
	public $num_queries = 0;

	//
	// Constructor
	//
	public function __construct($sqlserver, $sqluser, $sqlpassword, $database, $persistency = true) {

		$this->connect_string = "";

		if( $sqluser ) $this->connect_string .= "user=$sqluser ";
		if( $sqlpassword ) $this->connect_string .= "password=$sqlpassword ";

		if( $sqlserver )
		{
			if( strpos($sqlserver, ":") !== false )
			{
				list($sqlserver, $sqlport) = explode(":", $sqlserver);
				$this->connect_string .= "host=$sqlserver port=$sqlport ";
			}
			else
			{
				$this->connect_string .= "host=$sqlserver ";
			}
		}

		if( $database )
		{
			$this->dbname = $database;
			$this->connect_string .= "dbname=$database";
		}

		$this->persistency = $persistency;

		$this->db_connect_id = ( $this->persistency ) ? pg_pconnect($this->connect_string) : pg_connect($this->connect_string);

		return ( $this->db_connect_id ) ? $this->db_connect_id : false;
	}

	//
	// Other base methods
	//
	public function sql_close()
	{
		if( $this->db_connect_id )
		{
			//
			// Commit any remaining transactions
			//
			if( $this->in_transaction )
			{
				@pg_query($this->db_connect_id, "COMMIT");
			}

			if( $this->query_result )
			{
				@pg_freeresult($this->query_result);
			}

			return @pg_close($this->db_connect_id);
		}
		else
		{
			return false;
		}
	}

	//
	// Query method
	//
	public function sql_query($query = "", $transaction = false)
	{
		//
		// Remove any pre-existing queries
		//
		unset($this->query_result);
		if( $query != "" )
		{
			$this->num_queries++;

			if( $transaction == self::BEGIN_TRANSACTION && !$this->in_transaction )
			{
				$this->in_transaction = TRUE;

				if( !@pg_query($this->db_connect_id, "BEGIN") )
				{
					return false;
				}
			}

			$this->query_result = @pg_query($this->db_connect_id, $query);
			if( $this->query_result )
			{
				if( $transaction == self::END_TRANSACTION )
				{
					$this->in_transaction = FALSE;

					if( !@pg_query($this->db_connect_id, "COMMIT") )
					{
						@pg_query($this->db_connect_id, "ROLLBACK");
						return false;
					}
				}

				$this->last_query_text[$this->query_result] = $query;
				$this->rownum[$this->query_result] = 0;

				unset($this->row[$this->query_result]);
				unset($this->rowset[$this->query_result]);

				return $this->query_result;
			}
			else
			{
				if( $this->in_transaction )
				{
					@pg_query($this->db_connect_id, "ROLLBACK");
				}
				$this->in_transaction = FALSE;

				return false;
			}
		}
		else
		{
			if( $transaction == self::END_TRANSACTION && $this->in_transaction )
			{
				$this->in_transaction = FALSE;

				if( !@pg_query($this->db_connect_id, "COMMIT") )
				{
					@pg_query($this->db_connect_id, "ROLLBACK");
					return false;
				}
			}

			return true;
		}
	}

	//
	// Other query methods
	//
	public function sql_numrows($query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		return ( $query_id ) ? @pg_numrows($query_id) : false;
	}

	public function sql_numfields($query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		return ( $query_id ) ? @pg_numfields($query_id) : false;
	}

	public function sql_fieldname($offset, $query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		return ( $query_id ) ? @pg_fieldname($query_id, $offset) : false;
	}

	public function sql_fieldtype($offset, $query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		return ( $query_id ) ? @pg_fieldtype($query_id, $offset) : false;
	}

	public function sql_fetchrow($query_id = 0, $pgResultType=PGSQL_ASSOC)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		if($query_id)
		{

			$this->row = @pg_fetch_array($query_id, $this->rownum[$query_id], $pgResultType);

			if( $this->row )
			{
				$this->rownum[$query_id]++;
				return $this->row;
			}
		}

		return false;
	}

	public function sql_fetchrowset($query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		if( $query_id )
		{
			unset($this->rowset[$query_id]);
			unset($this->row[$query_id]);
			$this->rownum[$query_id] = 0;

			while( $this->rowset = @pg_fetch_array($query_id, $this->rownum[$query_id], PGSQL_ASSOC) )
			{
				$result[] = $this->rowset;
				$this->rownum[$query_id]++;
			}

			return $result;
		}

		return false;
	}

	public function sql_fetchfield($field, $row_offset=-1, $query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		if( $query_id )
		{
			if( $row_offset != -1 )
			{
				$this->row = @pg_fetch_array($query_id, $row_offset, PGSQL_ASSOC);
			}
			else
			{
				if( $this->rownum[$query_id] )
				{
					$this->row = @pg_fetch_array($query_id, $this->rownum[$query_id]-1, PGSQL_ASSOC);
				}
				else
				{
					$this->row = @pg_fetch_array($query_id, $this->rownum[$query_id], PGSQL_ASSOC);

					if( $this->row )
					{
						$this->rownum[$query_id]++;
					}
				}
			}

			return $this->row[$field];
		}

		return false;
	}

	public function sql_rowseek($offset, $query_id = 0)
	{

		if(!$query_id)
		{
			$query_id = $this->query_result;
		}

		if( $query_id )
		{
			if( $offset > -1 )
			{
				$this->rownum[$query_id] = $offset;
				return true;
			}
			else
			{
				return false;
			}
		}

		return false;
	}

	public function sql_nextid( $idFieldname = 'id' )
	{
		$query_id = $this->query_result;

		if($query_id && $this->last_query_text[$query_id] != "")
		{
			if( preg_match("/^\s*INSERT\s+INTO\s+([a-z0-9\_\-]+)/is", $this->last_query_text[$query_id], $tablename) )
			{
				$query = "SELECT currval('" . $tablename[1] . "_${idFieldname}_seq') AS last_value";
				$temp_q_id =  @pg_query($this->db_connect_id, $query);
				if( !$temp_q_id )
				{
					return false;
				}

				$temp_result = @pg_fetch_array($temp_q_id, 0, PGSQL_ASSOC);

				return ( $temp_result ) ? $temp_result['last_value'] : false;
			}
		}

		return false;
	}

	public function sql_affectedrows($query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		return ( $query_id ) ? @pg_cmdtuples($query_id) : false;
	}

	public function sql_freeresult($query_id = 0)
	{
		if( !$query_id )
		{
			$query_id = $this->query_result;
		}

		return ( $query_id ) ? @pg_freeresult($query_id) : false;
	}

	public function sql_error($query_id = 0)
	{
		return [
			'message' => @pg_last_error($this->db_connect_id)
		];
	}

	public function sql_escape_string( $string ) {

		return pg_escape_string( $this->db_connect_id, $string );

	}

	public function escape( $string ) {

		return pg_escape_string( $this->db_connect_id, $string );

	}

	public function sql_escape_literal( $string ) {

		return pg_escape_literal( $this->db_connect_id, $string );

	}

	public function sql_escape_identifier( $tableOrFieldName ) {

		return pg_escape_identifier( $this->db_connect_id, $tableOrFieldName );

	}

	public function sql_escape_bytea( $binaryData ) {

		return pg_escape_bytea( $this->db_connect_id, $binaryData );

	}

	public function sql_set_charset($charset = 'UTF8') {

		return pg_set_client_encoding($this->db_connect_id, $charset);

	}

}
