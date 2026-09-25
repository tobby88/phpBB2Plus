<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Filesystem recovery primitive. Callers must bind seal() to a committed DB
// receipt BEFORE apply(), hold the style owner, and decide from that receipt
// whether rollback is still allowed. This class does not decide SQL outcomes.
class PhpbbStyleImportJournal
{
	var $directory; var $manifest; var $digest;

	static function fail() { phpbb_acl_error('xs_import_failed'); }
	static function hash_value($value) { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value); }
	static function operation($value) { return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value); }
	static function path_ok($path)
	{
		if (!is_string($path) || strlen($path) > 512 || preg_match('//u', $path) !== 1) { return false; }
		$parts = explode('/', $path);
		if (count($parts) < 2 || count($parts) > 32 || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,29}$/D', $parts[0])
			|| strpos($parts[0], '..') !== false || preg_match('#(?:^|/)(?:\.htaccess|\.user\.ini|xs_config\.cfg)(?:/|$)#i', $path)
			|| preg_match('#\.(?:php[0-9]*|phtml|phar|cgi|pl|sh)$#i', $path)) { return false; }
		foreach ($parts as $part)
		{
			if ($part === '' || trim($part, ' .') !== $part || stripos($part, 'xs_import_') === 0 || preg_match('~[\\\\:\x00-\x1f\x7f]~', $part)
				|| preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)) { return false; }
		}
		return true;
	}
	static function base($base)
	{
		if (!is_string($base) || is_link($base) || !is_dir($base) || ($path = realpath($base)) === false) { self::fail(); }
		return $path;
	}
	static function envelope() { return "<?php exit; __halt_compiler();\n"; }
	function payload_name($index, $version) { return sprintf('%04d-%s.backup.php', $index, $version); }
	function store($name, $bytes)
	{
		if (is_link($this->directory) || realpath($this->directory) !== $this->directory) { self::fail(); }
		// Apache cache denial and 0600 remain defense in depth. These files also
		// terminate if requested through PHP; arbitrary payload PHP is not parsed.
		$bytes = self::envelope() . $bytes;
		$path = $this->directory . DIRECTORY_SEPARATOR . $name;
		$handle = @fopen($path, 'x+b'); if (!$handle) { self::fail(); }
		try
		{
			if (!@chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) { self::fail(); }
		}
		finally { fclose($handle); }
		if (@hash_file('sha256', $path) !== hash('sha256', $bytes)) { self::fail(); }
	}
	function load($name, $limit)
	{
		$path = $this->directory . DIRECTORY_SEPARATOR . $name; clearstatcache(true, $path);
		if (is_link($this->directory) || realpath($this->directory) !== $this->directory || is_link($path) || !is_file($path)) { self::fail(); }
		$envelope = self::envelope(); $size = strlen($envelope);
		$bytes = @file_get_contents($path, false, null, 0, $limit + $size + 1);
		if (!is_string($bytes) || strlen($bytes) > $limit + $size || substr($bytes, 0, $size) !== $envelope) { self::fail(); }
		return (string)substr($bytes, $size);
	}
	function seal() { return $this->digest; }
	function id() { return $this->manifest['operation']; }
	function context() { return $this->manifest['context']; }
	function stage_tag($index, $version) { return substr(hash('sha256', $this->id() . ':' . $index . ':' . $version), 0, 32); }

	static function prepare($files, $base, $identity, $desired, $guard, $context = array(), $extra_directories = array(), $operation = null)
	{
		$context_json = is_array($context) ? json_encode($context) : false;
		if (!is_string($identity) || $identity === '' || !is_array($desired) || !$desired || count($desired) > 5000
			|| !is_string($context_json) || strlen($context_json) > 32768 || !is_array($extra_directories) || count($extra_directories) > 5000) { self::fail(); }
		$base = self::base($base); $directories = array(); $seen = array(); $template = null; $new_size = 0;
		foreach ($desired as $path => $bytes)
		{
			if (!self::path_ok($path) || !is_string($bytes) || strlen($bytes) > 33554432) { self::fail(); }
			$new_size += strlen($bytes); if ($new_size > 67108864) { self::fail(); }
			$parts = explode('/', $path);
			if ($template === null) { $template = $parts[0]; } elseif ($template !== $parts[0]) { self::fail(); }
			$key = strtolower($path); if (isset($seen[$key])) { self::fail(); } $seen[$key] = true;
			array_pop($parts);
			while ($parts) { $directories[implode('/', $parts)] = true; array_pop($parts); }
		}
		foreach ($extra_directories as $path)
		{
			if (!is_string($path) || !self::path_ok($path . '/__directory__')) { self::fail(); }
			$parts = explode('/', $path); if ($parts[0] !== $template) { self::fail(); }
			while ($parts) { $directories[implode('/', $parts)] = true; array_pop($parts); }
		}
		if (count($directories) > 10000) { self::fail(); }
		foreach ($directories as $path => $ignored) { if (isset($seen[strtolower($path)])) { self::fail(); } }
		$directories = array_keys($directories);
		usort($directories, function ($a, $b) { $d = substr_count($a, '/') - substr_count($b, '/'); return $d ? $d : strcmp($a, $b); });
		call_user_func($guard);
		foreach ($directories as $path) { if (!in_array($files->kind($path), array(null, 'dir'), true)) { self::fail(); } }
		foreach ($desired as $path => $bytes) { if (!in_array($files->kind($path), array(null, 'file'), true)) { self::fail(); } }
		$transport = hash('sha256', $files->identity());
		if ($operation === null) { $operation = bin2hex(phpbb_random_bytes(16)); }
		if (!self::operation($operation)) { self::fail(); }
		$journal = new self();
		$journal->directory = $base . DIRECTORY_SEPARATOR . 'xs-import-' . $operation . '.backup';
		if (!@mkdir($journal->directory, 0700)) { self::fail(); }
		if (!@chmod($journal->directory, 0700)) { @rmdir($journal->directory); self::fail(); }
		$journal->manifest = array('version'=>1, 'operation'=>$operation, 'target'=>hash('sha256', $identity), 'transport'=>$transport, 'context'=>$context, 'files'=>array(), 'directories'=>$directories);
		$complete = false;
		try
		{
			$index = 0; $old_size = 0;
			foreach ($desired as $path => $bytes)
			{
				call_user_func($guard); $kind = $files->kind($path); $old = null;
				if ($kind === 'file')
				{
					$before = $files->read($path, 33554432); $old_size += strlen($before); if ($old_size > 67108864) { self::fail(); }
					$old = hash('sha256', $before); $journal->store($journal->payload_name($index, 'old'), $before);
				}
				elseif ($kind !== null) { self::fail(); }
				$journal->store($journal->payload_name($index, 'new'), $bytes);
				$journal->manifest['files'][] = array('path'=>$path, 'old'=>$old, 'new'=>hash('sha256', $bytes)); $index++;
			}
			$json = json_encode($journal->manifest);
			if (!is_string($json) || strlen($json) > 4194304) { self::fail(); }
			$journal->store('manifest.backup.php', $json); $journal->digest = hash('sha256', $json);
			$journal->preflight($files, $guard, true); $complete = true;
		}
		finally
		{
			// There have been no destination writes or published receipt yet.
			if (!$complete && !is_link($journal->directory) && realpath($journal->directory) === $journal->directory)
			{
				foreach ((array)@scandir($journal->directory) as $name)
				{
					if (is_link($journal->directory) || realpath($journal->directory) !== $journal->directory) { break; }
					if ($name === 'manifest.backup.php' || preg_match('/^[0-9]{4}-(?:old|new)\.backup\.php$/D', $name)) { @unlink($journal->directory . DIRECTORY_SEPARATOR . $name); }
				}
				@rmdir($journal->directory);
			}
		}
		return $journal;
	}

	static function reopen($base, $operation, $seal, $identity)
	{
		if (!self::operation($operation) || !self::hash_value($seal) || !is_string($identity) || $identity === '') { self::fail(); }
		$journal = new self(); $journal->directory = self::base($base) . DIRECTORY_SEPARATOR . 'xs-import-' . $operation . '.backup';
		$json = $journal->load('manifest.backup.php', 4194304);
		if (!hash_equals($seal, hash('sha256', $json))) { self::fail(); }
		$data = json_decode($json, true);
		if (!is_array($data) || !isset($data['version'], $data['operation'], $data['target'], $data['transport'], $data['context'], $data['files'], $data['directories'])
			|| $data['version'] !== 1 || $data['operation'] !== $operation || $data['target'] !== hash('sha256', $identity) || !self::hash_value($data['transport'])
			|| !is_array($data['files']) || !$data['files'] || count($data['files']) > 5000 || !is_array($data['directories']) || count($data['directories']) > 10000
			|| !is_array($data['context']) || strlen(json_encode($data['context'])) > 32768) { self::fail(); }
		$seen = array(); $expected_dirs = array(); $template = null;
		foreach ($data['files'] as $index => $item)
		{
			if (!is_int($index) || $index !== count($seen) || !is_array($item) || !isset($item['path'], $item['new']) || !array_key_exists('old', $item)
				|| !self::path_ok($item['path']) || !self::hash_value($item['new']) || ($item['old'] !== null && !self::hash_value($item['old']))) { self::fail(); }
			$parts = explode('/', $item['path']);
			if ($template === null) { $template = $parts[0]; } elseif ($template !== $parts[0]) { self::fail(); }
			$key = strtolower($item['path']); if (isset($seen[$key])) { self::fail(); } $seen[$key] = true;
			array_pop($parts); while ($parts) { $expected_dirs[implode('/', $parts)] = true; array_pop($parts); }
		}
		foreach ($data['directories'] as $path)
		{
			if (!is_string($path) || !self::path_ok($path . '/__directory__')) { self::fail(); }
			$parts = explode('/', $path); if ($parts[0] !== $template) { self::fail(); }
			while ($parts) { $expected_dirs[implode('/', $parts)] = true; array_pop($parts); }
		}
		foreach ($expected_dirs as $path => $ignored) { if (isset($seen[strtolower($path)])) { self::fail(); } }
		$expected_dirs = array_keys($expected_dirs);
		usort($expected_dirs, function ($a, $b) { $d = substr_count($a, '/') - substr_count($b, '/'); return $d ? $d : strcmp($a, $b); });
		if ($data['directories'] !== $expected_dirs) { self::fail(); }
		$journal->manifest = $data; $journal->digest = $seal; $journal->verify_payloads();
		return $journal;
	}
	function payload($index, $version)
	{
		$bytes = $this->load($this->payload_name($index, $version), 33554432);
		if (hash('sha256', $bytes) !== $this->manifest['files'][$index][$version]) { self::fail(); }
		return $bytes;
	}
	function verify_payloads()
	{
		$totals = array('old'=>0, 'new'=>0);
		foreach ($this->manifest['files'] as $index => $item)
		{
			foreach ($totals as $version => $unused)
			{
				if ($item[$version] === null) { continue; }
				$totals[$version] += strlen($this->payload($index, $version)); if ($totals[$version] > 67108864) { self::fail(); }
			}
		}
	}
	function current($files, $item)
	{
		$kind = $files->kind($item['path']); if ($kind === null) { return null; }
		if ($kind !== 'file') { self::fail(); }
		return hash('sha256', $files->read($item['path'], 33554432));
	}
	function preflight($files, $guard, $original_only = false)
	{
		if (hash('sha256', $files->identity()) !== $this->manifest['transport']) { self::fail(); }
		$this->verify_payloads();
		foreach ($this->manifest['directories'] as $path)
		{
			call_user_func($guard); if (!in_array($files->kind($path), array(null, 'dir'), true)) { self::fail(); }
		}
		foreach ($this->manifest['files'] as $index => $item)
		{
			call_user_func($guard); $current = $this->current($files, $item);
			if ($current !== $item['old'] && ($original_only || $current !== $item['new'])) { self::fail(); }
			foreach (array('old', 'new') as $version)
			{
				if ($item[$version] !== null) { $files->stage_probe($item['path'], $this->payload($index, $version), $this->stage_tag($index, $version)); }
			}
		}
	}
	function apply($files, $guard, $rollback = false)
	{
		$this->preflight($files, $guard);
		if (!$rollback)
		{
			foreach ($this->manifest['directories'] as $path)
			{
				call_user_func($guard); $kind = $files->kind($path);
				if ($kind === null) { if (!$files->directory($path) || $files->kind($path) !== 'dir') { self::fail(); } }
				elseif ($kind !== 'dir') { self::fail(); }
			}
		}
		foreach ($this->manifest['files'] as $index => $item)
		{
			foreach (array('old', 'new') as $version)
			{
				if ($item[$version] !== null) { $files->stage_cleanup($item['path'], $this->payload($index, $version), $guard, $this->stage_tag($index, $version)); }
			}
			call_user_func($guard); $current = $this->current($files, $item); $wanted = $item[$rollback ? 'old' : 'new'];
			if ($current === $wanted) { continue; }
			if ($current !== $item[$rollback ? 'new' : 'old']) { self::fail(); }
			$boundary = function () use ($files, $item, $current, $guard) {
				call_user_func($guard); if ($this->current($files, $item) !== $current) { self::fail(); }
			};
			if ($wanted === null)
			{
				call_user_func($boundary); if (!$files->erase($item['path'], 'file')) { self::fail(); }
			}
			else { $files->put($item['path'], $this->payload($index, $rollback ? 'old' : 'new'), $boundary, $this->stage_tag($index, $rollback ? 'old' : 'new')); }
			if ($this->current($files, $item) !== $wanted) { self::fail(); }
		}
		// Keep empty directories: no durable ownership proof distinguishes ours
		// from a concurrently recreated directory. Never delete unrelated data.
		call_user_func($guard);
	}
}
