<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_files.php';

class PhpbbStyleImportLocal extends PhpbbStyleLocalFiles
{
	function directory($path) { return @mkdir($this->path($path), 0755); }
	function put($path, $contents, $guard)
	{
		$target = $this->path($path); $temporary = @tempnam(dirname($target), 'xs_import_');
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
	function directory($path) { return @ftp_mkdir($this->ftp, $this->root . '/' . $path) !== false; }
	function put($path, $contents, $guard)
	{
		$target = $this->root . '/' . $path;
		$temporary = dirname($target) . '/xs_import_' . bin2hex(phpbb_random_bytes(16)) . '.tmp';
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
