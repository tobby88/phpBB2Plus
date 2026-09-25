<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_files.php';

class PhpbbStyleImportLocal extends PhpbbStyleLocalFiles
{
	function identity() { return 'local:' . $this->root; }
	function read($path, $limit)
	{
		if (!is_int($limit) || $limit < 0 || $limit > 33554432 || $this->kind($path) !== 'file') { phpbb_acl_error('xs_import_failed'); }
		$bytes = @file_get_contents($this->path($path), false, null, 0, $limit + 1);
		if (!is_string($bytes) || strlen($bytes) > $limit || $this->kind($path) !== 'file') { phpbb_acl_error('xs_import_failed'); }
		return $bytes;
	}
	function directory($path) { return @mkdir($this->path($path), 0755); }
	function stage_name($path, $tag)
	{
		if (!is_string($tag) || !preg_match('/^[a-f0-9]{32}$/D', $tag)) { phpbb_acl_error('xs_import_failed'); }
		return dirname($path) . '/xs_import_' . $tag . '.tmp';
	}
	function stage_probe($path, $contents, $tag)
	{
		$stage = $this->stage_name($path, $tag); $kind = $this->kind($stage);
		if ($kind === null) { return false; }
		if ($kind !== 'file') { phpbb_acl_error('xs_import_failed'); }
		$bytes = $this->read($stage, strlen($contents));
		if ($bytes !== (string)substr($contents, 0, strlen($bytes))) { phpbb_acl_error('xs_import_failed'); }
		return true;
	}
	function stage_cleanup($path, $contents, $guard, $tag)
	{
		call_user_func($guard);
		if ($this->stage_probe($path, $contents, $tag) && !$this->erase($this->stage_name($path, $tag), 'file')) { phpbb_acl_error('xs_import_failed'); }
	}
	function put($path, $contents, $guard, $tag = null)
	{
		$target = $this->path($path);
		if ($tag !== null)
		{
			$this->stage_cleanup($path, $contents, $guard, $tag);
			$temporary = $this->path($this->stage_name($path, $tag)); $handle = @fopen($temporary, 'x+b');
			if (!$handle) { phpbb_acl_error('xs_import_failed'); } fclose($handle);
		}
		else { $temporary = @tempnam(dirname($target), 'xs_import_'); }
		if ($temporary === false || dirname($temporary) !== dirname($target)) { if ($temporary !== false) { @unlink($temporary); } phpbb_acl_error('xs_import_failed'); }
		try
		{
			if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !@chmod($temporary, 0644)) { phpbb_acl_error('xs_import_failed'); }
			call_user_func($guard);
			if (!in_array($this->kind($path), array(null, 'file'), true) || !@rename($temporary, $target)
				|| @hash_file('sha256', $target) !== hash('sha256', $contents)) { phpbb_acl_error('xs_import_failed'); }
		}
		finally { if (is_file($temporary)) { @unlink($temporary); } }
	}
}

class PhpbbStyleImportFtp extends PhpbbStyleFtpFiles
{
	var $endpoint;
	function __construct($ftp, $endpoint = null) { parent::__construct($ftp); $this->endpoint = $endpoint; }
	function identity()
	{
		// Recovery callers bind the host/port/account, never the password.
		if (!is_string($this->endpoint) || $this->endpoint === '') { phpbb_acl_error('xs_import_failed'); }
		$this->entries('');
		return 'ftp:' . $this->endpoint . "\0" . $this->root;
	}
	function read($path, $limit)
	{
		if (!is_int($limit) || $limit < 0 || $limit > 33554432 || $this->kind($path) !== 'file') { phpbb_acl_error('xs_import_failed'); }
		$target = $this->root . '/' . $path; $size = @ftp_size($this->ftp, $target);
		if (!is_int($size) || $size < 0 || $size > $limit) { phpbb_acl_error('xs_import_failed'); }
		$stream = tmpfile(); if ($stream === false) { phpbb_acl_error('xs_import_failed'); } $status = FTP_FAILED;
		try
		{
			$started = microtime(true); $status = @ftp_nb_fget($this->ftp, $stream, $target, FTP_BINARY);
			while ($status === FTP_MOREDATA)
			{
				$stat = fstat($stream);
				if (!$stat || $stat['size'] > $limit || microtime(true) - $started > 30) { phpbb_acl_error('xs_import_failed'); }
				$status = @ftp_nb_continue($this->ftp);
			}
			if ($status !== FTP_FINISHED || !rewind($stream)) { phpbb_acl_error('xs_import_failed'); }
			$bytes = stream_get_contents($stream, $limit + 1);
			if (!is_string($bytes) || strlen($bytes) !== $size || @ftp_size($this->ftp, $target) !== $size || $this->kind($path) !== 'file') { phpbb_acl_error('xs_import_failed'); }
			return $bytes;
		}
		finally
		{
			// An oversized/stalled transfer must not keep writing to a temp file
			// after failure. The unusable transfer connection is deliberately closed.
			if ($status === FTP_MOREDATA) { @ftp_close($this->ftp); $this->ftp = null; }
			fclose($stream);
		}
	}
	function stage_name($path, $tag)
	{
		if (!is_string($tag) || !preg_match('/^[a-f0-9]{32}$/D', $tag)) { phpbb_acl_error('xs_import_failed'); }
		return dirname($path) . '/xs_import_' . $tag . '.tmp';
	}
	function stage_probe($path, $contents, $tag)
	{
		$stage = $this->stage_name($path, $tag); $kind = $this->kind($stage);
		if ($kind === null) { return false; }
		if ($kind !== 'file') { phpbb_acl_error('xs_import_failed'); }
		$bytes = $this->read($stage, strlen($contents));
		if ($bytes !== (string)substr($contents, 0, strlen($bytes))) { phpbb_acl_error('xs_import_failed'); }
		return true;
	}
	function stage_cleanup($path, $contents, $guard, $tag)
	{
		call_user_func($guard);
		if ($this->stage_probe($path, $contents, $tag) && !$this->erase($this->stage_name($path, $tag), 'file')) { phpbb_acl_error('xs_import_failed'); }
	}
	function directory($path) { return @ftp_mkdir($this->ftp, $this->root . '/' . $path) !== false; }
	function put($path, $contents, $guard, $tag = null)
	{
		$target = $this->root . '/' . $path;
		if ($tag !== null) { $this->stage_cleanup($path, $contents, $guard, $tag); }
		$temporary = $tag === null ? dirname($target) . '/xs_import_' . bin2hex(phpbb_random_bytes(16)) . '.tmp' : $this->root . '/' . $this->stage_name($path, $tag);
		$stream = tmpfile(); if ($stream === false) { phpbb_acl_error('xs_import_failed'); }
		try
		{
			if (fwrite($stream, $contents) !== strlen($contents) || !rewind($stream)) { phpbb_acl_error('xs_import_failed'); }
			call_user_func($guard);
			if (!@ftp_fput($this->ftp, $temporary, $stream, FTP_BINARY) || @ftp_size($this->ftp, $temporary) !== strlen($contents)) { phpbb_acl_error('xs_import_failed'); }
			if (!ftruncate($stream, 0) || !rewind($stream) || !@ftp_fget($this->ftp, $stream, $temporary, FTP_BINARY) || !rewind($stream)) { phpbb_acl_error('xs_import_failed'); }
			$hash = hash_init('sha256'); $bytes = hash_update_stream($hash, $stream);
			if ($bytes !== strlen($contents) || hash_final($hash) !== hash('sha256', $contents)) { phpbb_acl_error('xs_import_failed'); }
			call_user_func($guard);
			if (!in_array($this->kind($path), array(null, 'file'), true) || !@ftp_rename($this->ftp, $temporary, $target)) { phpbb_acl_error('xs_import_failed'); }
		}
		finally { fclose($stream); @ftp_delete($this->ftp, $temporary); }
	}
}

function phpbb_style_import_publish($files, $template_name, $entries, $archive, $guard)
{
	$directories = array($template_name => true); $targets = array();
	foreach ($entries as $entry)
	{
		$name = rtrim($entry['filename'], '/'); if ($name === '') { continue; }
		$path = $template_name . '/' . $name;
		if ($entry['typeflag'] === 5) { $directories[$path] = true; } else { $targets[$path] = $entry; }
		$parts = explode('/', $path); if (count($parts) > 32) { phpbb_acl_error('xs_import_failed'); }
		array_pop($parts);
		while ($parts) { $directories[implode('/', $parts)] = true; array_pop($parts); }
	}
	$directories = array_keys($directories);
	usort($directories, function ($a, $b) { $depth = substr_count($a, '/') - substr_count($b, '/'); return $depth ? $depth : strcmp($a, $b); });
	// Full destination preflight: reject links/type conflicts even if they occur
	// after otherwise writable entries. Never follow an existing linked parent.
	call_user_func($guard);
	foreach ($directories as $path) { if (!in_array($files->kind($path), array(null, 'dir'), true)) { phpbb_acl_error('xs_import_failed'); } }
	foreach ($targets as $path => $entry) { if (!in_array($files->kind($path), array(null, 'file'), true)) { phpbb_acl_error('xs_import_failed'); } }
	foreach ($directories as $path)
	{
		call_user_func($guard); $kind = $files->kind($path);
		if ($kind === null) { if (!$files->directory($path) || $files->kind($path) !== 'dir') { phpbb_acl_error('xs_import_failed'); } }
		elseif ($kind !== 'dir') { phpbb_acl_error('xs_import_failed'); }
	}
	foreach ($targets as $path => $entry)
	{
		call_user_func($guard);
		if (!in_array($files->kind($path), array(null, 'file'), true)) { phpbb_acl_error('xs_import_failed'); }
		$files->put($path, substr($archive, $entry['offset'], $entry['size']), $guard);
	}
	call_user_func($guard);
}
