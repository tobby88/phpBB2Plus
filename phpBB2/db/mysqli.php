<?php
/***************************************************************************
 *                                 mysqli.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id$
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if(!defined("SQL_LAYER"))
{

define("SQL_LAYER","mysqli");

class sql_db
{

	var $db_connect_id;
	var $query_result;
	var $num_queries = 0;
	var $persistency;
	var $user;
	var $password;
	var $server;
	var $dbname;

	//
	// Constructor
	//
	function __construct($sqlserver, $sqluser, $sqlpassword, $database, $persistency = true)
	{
		$this->sql_db($sqlserver, $sqluser, $sqlpassword, $database, $persistency);
	}

	function sql_db($sqlserver, $sqluser, $sqlpassword, $database, $persistency = true)
	{
		if (function_exists('mysqli_report'))
		{
			mysqli_report(MYSQLI_REPORT_OFF);
		}

		$this->persistency = $persistency;
		$this->user = $sqluser;
		$this->password = $sqlpassword;
		$this->server = $sqlserver;
		$this->dbname = $database;

		if($this->persistency)
		{
			$this->db_connect_id = @mysqli_connect('p:' . $this->server, $this->user, $this->password, $this->dbname, NULL);
		}
		else
		{
			$this->db_connect_id = @mysqli_connect($this->server, $this->user, $this->password, $this->dbname, NULL);
		}

		if($this->db_connect_id)
		{
			// Keep the connection encoding aligned with the UTF-8 source,
			// templates, language files and fresh-install schema.
			if (!@mysqli_set_charset($this->db_connect_id, 'utf8mb4') || strtolower(@mysqli_character_set_name($this->db_connect_id)) != 'utf8mb4')
			{
				@mysqli_close($this->db_connect_id);
				$this->db_connect_id = false;
				return false;
			}

			if($database != "")
			{
				$this->dbname = $database;
				$dbselect = @mysqli_select_db($this->db_connect_id, $this->dbname);
				if(!$dbselect)
				{
					@mysqli_close($this->db_connect_id);
					$this->db_connect_id = $dbselect;
				}
			}
			return $this->db_connect_id;
		}
		else
		{
			return false;
		}
	}

	//
	// Other base methods
	//
	function sql_close()
	{
		if($this->db_connect_id)
		{
			// Closing the connection releases outstanding results. Re-freeing a
			// result already released by sql_freeresult() throws on PHP 8.
			$this->query_result = false;
			$result = @mysqli_close($this->db_connect_id);
			return $result;
		}
		else
		{
			return false;
		}
	}

	//
	// Base query method
	//
	function sql_query($query = "", $transaction = FALSE)
	{
		// Remove any pre-existing queries
		unset($this->query_result);
		if($query != "")
		{
			$this->num_queries++;

			$this->query_result = @mysqli_query($this->db_connect_id, $query);
		}
		if($this->query_result)
		{
			return $this->query_result;
		}
		else
		{
			return ( $transaction == END_TRANSACTION ) ? true : false;
		}
	}

	//
	// Other query methods
	//
	function sql_numrows($query_id = 0)
	{
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			$result = @mysqli_num_rows($query_id);
			return $result;
		}
		else
		{
			return false;
		}
	}
	function sql_affectedrows()
	{
		if($this->db_connect_id)
		{
			$result = @mysqli_affected_rows($this->db_connect_id);
			return $result;
		}
		else
		{
			return false;
		}
	}
	function sql_numfields($query_id = 0)
	{
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			$result = @mysqli_field_count($query_id);
			return $result;
		}
		else
		{
			return false;
		}
	}
	function sql_fieldname($offset, $query_id = 0)
	{
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			$field = @mysqli_fetch_field_direct($query_id, $offset);
			return $field->name;
		}
		else
		{
			return false;
		}
	}
	function sql_fieldtype($offset, $query_id = 0)
	{
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			$field = @mysqli_fetch_field_direct($query_id, $offset);
			return $field->type;
		}
		else
		{
			return false;
		}
	}
	function sql_fetchrow($query_id = 0)
	{
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			$result = @mysqli_fetch_array($query_id);
			return $result;
		}
		else
		{
			return false;
		}
	}
	function sql_fetchrowset($query_id = 0)
	{
		$result = array();
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			while($row = @mysqli_fetch_array($query_id))
			{
				$result[] = $row;
			}
			return $result;
		}
		else
		{
			return false;
		}
	}

	function mysqli_result($query_id, $rownum = 0, $field = 0)
	{
		$numrows = mysqli_num_rows($query_id);
		if ($numrows && $rownum <= ($numrows - 1) && $rownum >= 0)
		{
			mysqli_data_seek($query_id, $rownum);
			$row = (is_numeric($field)) ? mysqli_fetch_row($query_id) : mysqli_fetch_assoc($query_id);
			if (isset($row[$field]))
			{
				return $row[$field];
			}
		}
		return false;
	}

	function sql_fetchfield($field, $rownum = -1, $query_id = 0)
	{
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			if($rownum > -1)
			{
				$result = @mysqli_result($query_id, $rownum, $field);
			}
			else
			{
				$row = $this->sql_fetchrow();
				if($row)
				{
					$result = $row[$field];
				}
			}
			return $result;
		}
		else
		{
			return false;
		}
	}
	function sql_rowseek($rownum, $query_id = 0){
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}
		if($query_id)
		{
			$result = @mysqli_data_seek($query_id, $rownum);
			return $result;
		}
		else
		{
			return false;
		}
	}
	function sql_nextid(){
		if($this->db_connect_id)
		{
			$result = @mysqli_insert_id($this->db_connect_id);
			return $result;
		}
		else
		{
			return false;
		}
	}
	function sql_freeresult($query_id = 0){
		if(!$query_id)
		{
			$query_id = $this->query_result;
		}

		if ( $query_id instanceof mysqli_result )
		{
			@mysqli_free_result($query_id);
			if ($query_id === $this->query_result)
			{
				$this->query_result = false;
			}

			return true;
		}
		else
		{
			return false;
		}
	}
	function sql_error($query_id = 0)
	{
		if($this->db_connect_id instanceof mysqli)
		{
			$result['message'] = @mysqli_error($this->db_connect_id);
			$result['code'] = @mysqli_errno($this->db_connect_id);
		}
		else
		{
			$result['message'] = function_exists('mysqli_connect_error') ? mysqli_connect_error() : '';
			$result['code'] = function_exists('mysqli_connect_errno') ? mysqli_connect_errno() : 0;
		}

		return $result;
	}

} // class sql_db

} // if ... define

?>
