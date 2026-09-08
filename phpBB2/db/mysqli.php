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
	private $dedicated_connection_factory;

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
		// CrackerTracker deliberately unsets the public password after bootstrap.
		// Keep only an internal connection factory for coordinated writers; do not
		// restore the public field or re-include configuration during a request.
		$this->dedicated_connection_factory = static function () use ($sqlserver, $sqluser, $sqlpassword, $database)
		{
			$connection = new sql_db(preg_replace('/^p:/', '', $sqlserver), $sqluser, $sqlpassword, $database, false);
			unset($connection->password);
			return $connection;
		};

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
			if (!@mysqli_set_charset($this->db_connect_id, 'utf8mb4') ||
				strtolower(@mysqli_character_set_name($this->db_connect_id)) != 'utf8mb4' ||
				!@mysqli_query($this->db_connect_id, "SET collation_connection = 'utf8mb4_unicode_ci'"))
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
	function sql_dedicated_connection()
	{
		return is_callable($this->dedicated_connection_factory) ? call_user_func($this->dedicated_connection_factory) : false;
	}

	function __debugInfo()
	{
		// Debug dumps must not expose credentials captured by the factory.
		return array('connected' => (bool) $this->db_connect_id, 'num_queries' => $this->num_queries);
	}

	function sql_close()
	{
		$connection = $this->db_connect_id;
		$this->db_connect_id = false;
		$this->query_result = false;
		if (!($connection instanceof mysqli)) { return false; }
		try { return @mysqli_close($connection); }
		catch (\Exception $error) { return false; }
		catch (\Throwable $error) { return false; }
	}

	//
	// Base query method
	//
	function sql_query($query = "", $transaction = FALSE)
	{
		$this->query_result = false;
		// Preserve only the empty legacy END marker as a no-op. These old flags
		// do not start/commit a transaction and must never hide a failed query.
		if ($query === '') { return defined('END_TRANSACTION') && $transaction === END_TRANSACTION; }
		if (!is_string($query) || !($this->db_connect_id instanceof mysqli)) { return false; }
		$this->num_queries++;
		try { $this->query_result = @mysqli_query($this->db_connect_id, $query); }
		catch (\Exception $error) { return false; }
		catch (\Throwable $error) { return false; }
		return $this->query_result;
	}

	function sql_escape($value)
	{
		return mysqli_real_escape_string($this->db_connect_id, (string) $value);
	}

	//
	// Other query methods
	//
	// Return a live result only. PHP 8 throws for released result objects;
	// older mysqli versions warn/return false. Keep one consistent API contract.
	function sql_result($query_id = 0)
	{
		if ($query_id === 0) { $query_id = $this->query_result; }
		if (!($query_id instanceof mysqli_result)) { return false; }
		try { return @mysqli_num_fields($query_id) > 0 ? $query_id : false; }
		catch (\Exception $error) { return false; }
		catch (\Throwable $error) { return false; }
	}
	function sql_numrows($query_id = 0)
	{
		$result = $this->sql_result($query_id);
		return $result ? mysqli_num_rows($result) : false;
	}
	function sql_numfields($query_id = 0)
	{
		$result = $this->sql_result($query_id);
		return $result ? mysqli_num_fields($result) : false;
	}
	function sql_field($offset, $query_id = 0)
	{
		$result = $this->sql_result($query_id);
		if (!$result || !(is_int($offset) || (is_string($offset) && preg_match('/^[0-9]+$/D', $offset)))) { return false; }
		$offset = (int) $offset;
		if ($offset < 0 || $offset >= mysqli_num_fields($result)) { return false; }
		return mysqli_fetch_field_direct($result, $offset);
	}
	function sql_fieldname($offset, $query_id = 0)
	{
		$field = $this->sql_field($offset, $query_id);
		return $field ? $field->name : false;
	}
	function sql_fieldtype($offset, $query_id = 0)
	{
		$field = $this->sql_field($offset, $query_id);
		return $field ? $field->type : false;
	}
	function sql_fetchrow($query_id = 0)
	{
		$result = $this->sql_result($query_id);
		if (!$result) { return false; }
		$row = mysqli_fetch_array($result);
		return is_array($row) ? $row : false;
	}
	function sql_fetchrowset($query_id = 0)
	{
		$result = $this->sql_result($query_id);
		if (!$result) { return false; }
		$rows = array();
		while ($row = $this->sql_fetchrow($result)) { $rows[] = $row; }
		return $rows;
	}
	function mysqli_result($query_id, $rownum = 0, $field = 0)
	{
		if (!$this->sql_rowseek($rownum, $query_id)) { return false; }
		return $this->sql_fetchfield($field, -1, $query_id);
	}
	function sql_fetchfield($field, $rownum = -1, $query_id = 0)
	{
		$result = $this->sql_result($query_id);
		if (!$result || !(is_int($field) || is_string($field))) { return false; }
		if (!(is_int($rownum) || (is_string($rownum) && preg_match('/^-?[0-9]+$/D', $rownum)))) { return false; }
		if ((int) $rownum >= 0)
		{
			return $this->mysqli_result($result, (int) $rownum, $field);
		}
		$row = $this->sql_fetchrow($result);
		return is_array($row) && array_key_exists($field, $row) ? $row[$field] : false;
	}
	function sql_rowseek($rownum, $query_id = 0)
	{
		$result = $this->sql_result($query_id);
		if (!$result || !(is_int($rownum) || (is_string($rownum) && preg_match('/^[0-9]+$/D', $rownum)))) { return false; }
		$rownum = (int) $rownum;
		if ($rownum < 0 || $rownum >= mysqli_num_rows($result)) { return false; }
		return mysqli_data_seek($result, $rownum);
	}
	function sql_affectedrows()
	{
		return $this->db_connect_id ? mysqli_affected_rows($this->db_connect_id) : false;
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
		$query_id = $this->sql_result($query_id);

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
