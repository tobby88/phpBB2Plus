<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Plan the entire unused template tree before deleting any entry. Recheck its
// kind and current authority at each destructive boundary; never follow links.
abstract class PhpbbStyleFiles
{
	function leaf($name)
	{
		return is_string($name) && $name !== '' && $name !== '.' && $name !== '..'
			&& !preg_match('~[\\\\/\x00-\x1f\x7f]~', $name);
	}
	function plan($relative, &$plan, $depth = 0)
	{
		if ($depth > 32 || count($plan) > 100000) { phpbb_acl_error('xs_remove_failed'); }
		foreach ($this->entries($relative) as $name=>$kind)
		{
			if (count($plan) >= 100000) { phpbb_acl_error('xs_remove_failed'); }
			if (!$this->leaf((string)$name) || !in_array($kind, array('dir','file','link'), true)) { phpbb_acl_error('xs_remove_failed'); }
			$path = $relative . '/' . $name;
			if ($kind === 'dir') { $this->plan($path, $plan, $depth + 1); }
			$plan[] = array($path, $kind);
		}
	}
	function kind($relative)
	{
		$parts = explode('/', $relative); $parent = '';
		foreach ($parts as $i=>$name)
		{
			if (!$this->leaf($name)) { phpbb_acl_error('xs_remove_failed'); }
			$entries = $this->entries($parent);
			if (!isset($entries[$name])) { return null; }
			if ($i === count($parts)-1) { return $entries[$name]; }
			if ($entries[$name] !== 'dir') { phpbb_acl_error('xs_remove_failed'); }
			$parent .= ($parent === '' ? '' : '/') . $name;
		}
	}
	function remove($name, $guard)
	{
		if (!phpbb_style_removal_name($name) || strcasecmp($name, 'fisubsilversh') === 0) { phpbb_acl_error('xs_remove_protected'); }
		call_user_func($guard);
		$kind = $this->kind($name);
		if ($kind === null) { return; } // Confirmed absent: safe cleanup retry.
		if ($kind !== 'dir') { phpbb_acl_error('xs_remove_failed'); }
		$plan = array(); $this->plan($name, $plan); $plan[] = array($name, 'dir');
		foreach ($plan as $item)
		{
			call_user_func($guard);
			if ($this->kind($item[0]) !== $item[1] || !$this->erase($item[0], $item[1])) { phpbb_acl_error('xs_remove_failed'); }
		}
		call_user_func($guard);
		if ($this->kind($name) !== null) { phpbb_acl_error('xs_remove_failed'); }
	}
}

class PhpbbStyleLocalFiles extends PhpbbStyleFiles
{
	var $root;
	function __construct($root)
	{
		if (is_link($root) || !is_dir($root) || ($resolved = realpath($root)) === false) { phpbb_acl_error('xs_remove_failed'); }
		$this->root = rtrim($resolved, '/\\');
	}
	function path($relative)
	{
		$path = $this->root;
		foreach (explode('/', $relative) as $name)
		{
			if ($name === '' && $relative === '') { break; }
			if (!$this->leaf($name)) { phpbb_acl_error('xs_remove_failed'); }
			$path .= DIRECTORY_SEPARATOR . $name;
		}
		return $path;
	}
	function entries($relative)
	{
		$path = $this->path($relative); clearstatcache(true, $path);
		if (is_link($path) || realpath($path) !== $path || !is_dir($path)) { phpbb_acl_error('xs_remove_failed'); }
		$names = @scandir($path); if (!is_array($names)) { phpbb_acl_error('xs_remove_failed'); } $out = array();
		foreach ($names as $name)
		{
			if ($name === '.' || $name === '..') { continue; }
			$child = $path . DIRECTORY_SEPARATOR . $name; clearstatcache(true, $child);
			$out[$name] = is_link($child) ? 'link' : (is_dir($child) ? 'dir' : (is_file($child) ? 'file' : 'unsupported'));
		}
		return $out;
	}
	function erase($relative, $kind)
	{
		$path = $this->path($relative);
		return $kind === 'dir' ? @rmdir($path) : @unlink($path);
	}
}

class PhpbbStyleFtpFiles extends PhpbbStyleFiles
{
	var $ftp; var $root = null; var $base = null;
	function __construct($ftp) { $this->ftp = $ftp; }
	function invoke($operation, $argument = null)
	{
		if ($operation === 'pwd') { return @ftp_pwd($this->ftp); }
		if ($operation === 'list')
		{
			if (function_exists('ftp_mlsd')) { $rows = @ftp_mlsd($this->ftp, $argument); if (is_array($rows)) { return array('mlsd', $rows); } }
			$rows = @ftp_rawlist($this->ftp, '-a ' . $argument); return is_array($rows) ? array('unix', $rows) : false;
		}
		return $operation === 'dir' ? @ftp_rmdir($this->ftp, $argument) : @ftp_delete($this->ftp, $argument);
	}
	function listing($path)
	{
		$listing = $this->invoke('list', $path); if (!is_array($listing)) { phpbb_acl_error('xs_remove_failed'); } $out = array();
		foreach ($listing[1] as $row)
		{
			if ($listing[0] === 'mlsd')
			{
				if (!isset($row['name'], $row['type'])) { phpbb_acl_error('xs_remove_failed'); }
				$name = $row['name']; $type = strtolower($row['type']);
				if (in_array($type, array('cdir','pdir'), true)) { continue; }
				$kind = $type === 'dir' ? 'dir' : ($type === 'file' ? 'file' : (strpos($type, 'os.unix=slink') === 0 ? 'link' : 'unsupported'));
			}
			else
			{
				if (preg_match('/^total [0-9]+$/D', $row)) { continue; }
				if (!preg_match('/^([dl-])[rwxstST-]{9}\s+\d+\s+\S+\s+\S+\s+\d+\s+\S+\s+\d+\s+[0-9:]+\s+(.+)$/D', $row, $m)) { phpbb_acl_error('xs_remove_failed'); }
				$name = $m[2]; $kind = $m[1] === 'd' ? 'dir' : ($m[1] === 'l' ? 'link' : 'file');
				if ($kind === 'link') { $arrow = strpos($name, ' -> '); if ($arrow === false) { phpbb_acl_error('xs_remove_failed'); } $name = substr($name, 0, $arrow); }
			}
			if ($name === '.' || $name === '..') { continue; }
			if (!$this->leaf($name) || $kind === 'unsupported' || isset($out[$name])) { phpbb_acl_error('xs_remove_failed'); }
			$out[$name] = $kind;
		}
		return $out;
	}
	function entries($relative)
	{
		if ($this->root === null)
		{
			$base = $this->invoke('pwd');
			if (!is_string($base) || substr($base, 0, 1) !== '/' || preg_match('~[\\\\\x00-\x1f\x7f]|(?:^|/)\.\.?(/|$)~', $base)) { phpbb_acl_error('xs_remove_failed'); }
			$entries = $this->listing($base);
			if (!isset($entries['templates']) || $entries['templates'] !== 'dir') { phpbb_acl_error('xs_remove_failed'); }
			$this->base = $base; $this->root = rtrim($base, '/') . '/templates';
		}
		$parent = $this->listing($this->base);
		if (!isset($parent['templates']) || $parent['templates'] !== 'dir') { phpbb_acl_error('xs_remove_failed'); }
		return $this->listing($this->root . ($relative === '' ? '' : '/' . $relative));
	}
	function erase($relative, $kind) { return $this->invoke($kind === 'dir' ? 'dir' : 'file', $this->root . '/' . $relative); }
}
