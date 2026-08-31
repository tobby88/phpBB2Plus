<?php
/***************************************************************************
 *                              acm_file.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: acm_file.php,v 1.5 2003/07/17 15:16:11 psotfx Exp $
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

class acm
{
	var $cache_dir = '';
	var $vars = array();
	var $vars_ts = array();
	var $modified = FALSE;

	function __construct()
	{
		$this->acm();
	}

	function acm()
	{
		global $phpbb_root_path;
		$this->cache_dir = $phpbb_root_path . 'pafiledb/cache/';
		$this->load();
	}

	function load()
	{
		$this->vars = array();
		$this->vars_ts = array();
		$cache_file = $this->cache_dir . 'data_global.cache';
		$cache_root = @realpath($this->cache_dir);
		if ($cache_root === false || !@is_file($cache_file) || @is_link($cache_file) ||
			@realpath(dirname($cache_file)) !== $cache_root)
		{
			return;
		}
		$size = @filesize($cache_file);
		if ($size === false || $size < 0 || $size > 4194304)
		{
			return;
		}
		$payload = phpbb_safe_unserialize_array(@file_get_contents($cache_file));
		if (is_array($payload) && isset($payload['vars'], $payload['timestamps']) &&
			is_array($payload['vars']) && is_array($payload['timestamps']))
		{
			$this->vars = $payload['vars'];
			$this->vars_ts = $payload['timestamps'];
		}
	}

	function unload()
	{
		$this->save();
		unset($this->vars);
		unset($this->vars_ts);
	}

	function save() 
	{
		if (!$this->modified)
		{
			return;
		}

		$cache_root = @realpath($this->cache_dir);
		$cache_file = $this->cache_dir . 'data_global.cache';
		if ($cache_root === false || @is_link($cache_file))
		{
			return false;
		}
		$serialized = serialize(array(
			'vars' => $this->vars,
			'timestamps' => $this->vars_ts,
		));
		if (strlen($serialized) > 4194304)
		{
			return false;
		}
		$temp = @tempnam($cache_root, 'data_');
		if ($temp === false)
		{
			return false;
		}
		$written = @file_put_contents($temp, $serialized, LOCK_EX);
		if ($written !== strlen($serialized))
		{
			@unlink($temp);
			return false;
		}
		@chmod($temp, 0644);
		if (!@rename($temp, $cache_file))
		{
			@unlink($temp);
			return false;
		}
		$this->modified = FALSE;
		return true;

	}

	function tidy($expire_time = 0)
	{
		$dir = @opendir($this->cache_dir);
		if (!$dir)
		{
			return;
		}
		while ($entry = readdir($dir))
		{
			if ($entry[0] == '.' || substr($entry, 0, 4) != 'sql_')
			{
				continue;
			}

			if (time() - $expire_time >= filemtime($this->cache_dir . $entry))
			{
				unlink($this->cache_dir . $entry);
			}
		}
		closedir($dir);

		if (file_exists($this->cache_dir . 'data_global.cache'))
		{
			foreach ($this->vars_ts as $varname => $timestamp)
			{
				if (time() - $expire_time >= $timestamp)
				{
					$this->destroy($varname);
				}
			}
		}
		else
		{
			$this->vars = $this->vars_ts = array();
			$this->modified = TRUE;
		}
	}

	function get($varname, $expire_time = 0)
	{
		return ($this->exists($varname, $expire_time)) ? $this->vars[$varname] : NULL;
	}

	function put($varname, $var)
	{
		$this->vars[$varname] = $var;
		$this->vars_ts[$varname] = time();
		$this->modified = TRUE;
	}

	function destroy($varname)
	{
		if (isset($this->vars[$varname]))
		{
			$this->modified = TRUE;
			unset($this->vars[$varname]);
			unset($this->vars_ts[$varname]);
		}
	}

	function exists($varname, $expire_time = 0)
	{
		if (!is_array($this->vars))
		{
			$this->load();
		}

		if ($expire_time > 0 && isset($this->vars_ts[$varname]))
		{
			if ($this->vars_ts[$varname] <= time() - $expire_time)
			{
				$this->destroy($varname);
				return FALSE;
			}
		}

		return isset($this->vars[$varname]);
	}
}
?>
