<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class AlbumFileStorageFailure extends RuntimeException {}

// Filesystem primitive for a durable Album operation, NOT an authorization
// decision. The guard must prove the owned DB lock and operation capability
// before every mutation. No caller input may select the application root.
// Callers must retain prepared receipts through failed/uncertain publication;
// this class deliberately never unlinks a published file in a failure handler.
class AlbumFileStorage
{
	const MAX_BYTES = 67108864;
	private $base;
	private $guard;
	private $directories = array('original' => 'album_mod/upload', 'small' => 'album_mod/upload/cache', 'medium' => 'album_mod/upload/med_cache');

	function __construct($guard, $root = null)
	{
		if (!is_callable($guard)) { throw new AlbumFileStorageFailure('Missing owned Album file capability'); }
		$this->guard = $guard;
		if ($root === null) { $root = dirname(__DIR__); }
		if (!is_string($root) || is_link($root) || !is_dir($root) || ($base = realpath($root)) === false || dirname($base) === $base) { throw new AlbumFileStorageFailure('Invalid Album file root'); }
		$this->base = $base;
		$this->check();
	}

	static function filename($name)
	{
		return is_string($name) && strlen($name) <= 255 && preg_match('//u', $name) === 1 && trim($name, ' .') === $name &&
			preg_match('/^(?!\.)[^\\\\:\/\x00-\x1f\x7f]+\.(?:jpe?g|png|gif)$/iD', $name) === 1 &&
			!preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])[ .]*(?:\.|$)/i', $name);
	}

	static function metadata($value)
	{
		if (!is_array($value) || count($value) !== 3 || !array_key_exists('present', $value) || !array_key_exists('bytes', $value) || !array_key_exists('sha256', $value)) { return false; }
		if ($value['present'] === false) { return $value['bytes'] === 0 && $value['sha256'] === null; }
		return $value['present'] === true && is_int($value['bytes']) && $value['bytes'] >= 0 && $value['bytes'] <= self::MAX_BYTES && is_string($value['sha256']) && preg_match('/^[a-f0-9]{64}$/D', $value['sha256']) === 1;
	}

	static function cache_filename($name)
	{
		return self::filename($name) || (is_string($name) && strlen($name) <= 255 && preg_match('/\.webp$/iD', $name) && self::filename(substr($name, 0, -5) . '.png'));
	}

	private function same_metadata($left, $right)
	{
		return self::metadata($left) && self::metadata($right) && $left['present'] === $right['present'] && $left['bytes'] === $right['bytes'] && $left['sha256'] === $right['sha256'];
	}

	function stage_name($operation, $scope, $name)
	{
		if (!is_string($operation) || preg_match('/^[a-f0-9]{32}$/D', $operation) !== 1 || !is_string($scope) || !isset($this->directories[$scope]) || !self::filename($name)) { throw new AlbumFileStorageFailure('Invalid staged Album identity'); }
		return '.album-stage-' . $operation . '-' . substr(hash('sha256', $scope . "\0" . $name), 0, 16) . '.tmp';
	}

	private function check($action = 'roots', $scope = null, $name = null, $operation = null, $metadata = null)
	{
		$context = array('action' => $action, 'scope' => $scope, 'name' => $name, 'operation' => $operation, 'metadata' => $metadata);
		if (call_user_func($this->guard, $context) !== true) { throw new AlbumFileStorageFailure('Album file capability no longer owned'); }
		if (is_link($this->base) || realpath($this->base) !== $this->base) { throw new AlbumFileStorageFailure('Album root changed'); }
		foreach ($this->directories as $relative)
		{
			$path = $this->base;
			foreach (explode('/', $relative) as $component)
			{
				$path .= DIRECTORY_SEPARATOR . $component; clearstatcache(true, $path);
				if (is_link($path) || !is_dir($path) || realpath($path) !== $path || !is_readable($path) || !is_writable($path)) { throw new AlbumFileStorageFailure('Album data directory changed or unavailable'); }
			}
		}
	}

	private function path($scope, $name)
	{
		if (!is_string($scope) || !isset($this->directories[$scope]) || !is_string($name)) { throw new AlbumFileStorageFailure('Invalid Album file scope'); }
		return $this->base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->directories[$scope]) . DIRECTORY_SEPARATOR . $name;
	}

	private function stat($path)
	{
		clearstatcache(true, $path); $stat = @lstat($path);
		if ($stat === false) { return false; }
		if (($stat['mode'] & 0170000) !== 0100000 || !is_int($stat['size']) || $stat['size'] < 0 || $stat['size'] > self::MAX_BYTES) { throw new AlbumFileStorageFailure('Album item is not a bounded regular file'); }
		return $stat;
	}

	private function same_stat($before, $after, $path_to_descriptor = false)
	{
		if (!is_array($before) || !is_array($after)) { return false; }
		foreach (array('dev', 'ino', 'mode', 'size', 'mtime', 'ctime') as $field)
		{
			if (!array_key_exists($field, $before) || !array_key_exists($field, $after)) { return false; }
			// PHP 5.6 on Windows reports descriptor dev=0 instead of the path's
			// drive number. Still compare path->path and descriptor->descriptor
			// strictly, and every other field across the two native stat APIs.
			if ($field === 'dev' && $path_to_descriptor && DIRECTORY_SEPARATOR === chr(92) && PHP_VERSION_ID < 70000 && is_int($before[$field]) && $after[$field] === 0) { continue; }
			if ($before[$field] !== $after[$field]) { return false; }
		}
		return true;
	}

	function snapshot($scope, $name)
	{
		if (!self::filename($name)) { throw new AlbumFileStorageFailure('Invalid Album basename'); }
		return $this->snapshot_file($scope, $name, 'snapshot');
	}

	function snapshot_cache($scope, $name)
	{
		if (!in_array($scope, array('small', 'medium'), true) || !self::cache_filename($name)) { throw new AlbumFileStorageFailure('Invalid Album cache basename'); }
		return $this->snapshot_file($scope, $name, 'cache-snapshot');
	}

	private function snapshot_file($scope, $name, $action)
	{
		$this->check($action, $scope, $name); $path = $this->path($scope, $name); $before = $this->stat($path);
		if ($before === false) { return array('present' => false, 'bytes' => 0, 'sha256' => null); }
		$handle = @fopen($path, 'rb'); if (!$handle) { throw new AlbumFileStorageFailure('Album snapshot unavailable'); }
		try
		{
			$opened = fstat($handle);
			if (!$this->same_stat($before, $opened, true)) { throw new AlbumFileStorageFailure('Album snapshot identity changed'); }
			$hash = hash_init('sha256'); $bytes = 0;
			while (!feof($handle))
			{
				$chunk = fread($handle, 65536); if ($chunk === false || ($chunk === '' && !feof($handle))) { throw new AlbumFileStorageFailure('Album snapshot read failed'); }
				$bytes += strlen($chunk); if ($bytes > self::MAX_BYTES) { throw new AlbumFileStorageFailure('Album snapshot grew'); } hash_update($hash, $chunk);
			}
			$this->check($action, $scope, $name);
			if ($bytes !== $before['size'] || !$this->same_stat($opened, fstat($handle)) || !$this->same_stat($before, $this->stat($path))) { throw new AlbumFileStorageFailure('Album snapshot changed during read'); }
			return array('present' => true, 'bytes' => $bytes, 'sha256' => hash_final($hash));
		}
		finally { fclose($handle); }
	}

	// The caller must persist the prepared operation, final names, complete
	// hashes and exact stage_name() identities BEFORE invoking this method.
	// No original/source is moved, deleted or closed. Failed stages remain for
	// the journal resolver; a complete final is never rolled back by this layer.
	function publish($operation, $scope, $name, $input, $expected)
	{
		if (!self::metadata($expected) || $expected['present'] !== true || $expected['bytes'] < 1 || !is_resource($input) || get_resource_type($input) !== 'stream') { throw new AlbumFileStorageFailure('Invalid Album publication input'); }
		$source = fstat($input); $position = ftell($input); $stream = stream_get_meta_data($input);
		if (!$source || ($source['mode'] & 0170000) !== 0100000 || $source['size'] !== $expected['bytes'] || !is_int($position) || !isset($stream['mode']) || (strpos($stream['mode'], 'r') !== 0 && strpos($stream['mode'], '+') === false)) { throw new AlbumFileStorageFailure('Album source is not an exact readable regular file'); }
		$stage = $this->stage_name($operation, $scope, $name); $this->check('publish', $scope, $name, $operation, $expected); $target = $this->path($scope, $name); $temporary = $this->path($scope, $stage);
		if ($this->stat($target) !== false) { throw new AlbumFileStorageFailure('Album destination already exists'); }
		$output = @fopen($temporary, 'x+b'); if (!$output) { throw new AlbumFileStorageFailure('Album stage is not exclusively owned'); }
		try
		{
			if (!@chmod($temporary, 0600) || fseek($input, 0) !== 0) { throw new AlbumFileStorageFailure('Album stage setup failed'); }
			$hash = hash_init('sha256'); $bytes = 0;
			while (!feof($input))
			{
				$chunk = fread($input, 65536); if ($chunk === false || ($chunk === '' && !feof($input))) { throw new AlbumFileStorageFailure('Album source read failed'); }
				$bytes += strlen($chunk); if ($bytes > $expected['bytes'] || fwrite($output, $chunk) !== strlen($chunk)) { throw new AlbumFileStorageFailure('Album stage write failed'); } hash_update($hash, $chunk);
			}
			if ($bytes !== $expected['bytes'] || hash_final($hash) !== $expected['sha256'] || !$this->same_stat($source, fstat($input)) || !fflush($output) || (function_exists('fsync') && !fsync($output))) { throw new AlbumFileStorageFailure('Album stage content or durability differs'); }
			fclose($output); $output = null;
			$this->check('publish', $scope, $name, $operation, $expected);
			if ($this->stat($target) !== false || !@rename($temporary, $target)) { throw new AlbumFileStorageFailure('Album final publication refused'); }
			if (!@chmod($target, 0664) || !$this->same_metadata($this->snapshot($scope, $name), $expected)) { throw new AlbumFileStorageFailure('Album published bytes unavailable'); }
			return true;
		}
		finally
		{
			if (is_resource($output)) { fclose($output); }
			if (is_resource($input)) { fseek($input, $position); }
		}
	}

	function remove_exact($scope, $name, $expected)
	{
		return $this->remove_snapshot($scope, $name, $expected, false);
	}

	function remove_cache_exact($scope, $name, $expected)
	{
		if (!in_array($scope, array('small', 'medium'), true) || !self::cache_filename($name)) { throw new AlbumFileStorageFailure('Invalid Album cache removal scope'); }
		return $this->remove_snapshot($scope, $name, $expected, true);
	}

	private function remove_snapshot($scope, $name, $expected, $cache)
	{
		if (!self::metadata($expected)) { throw new AlbumFileStorageFailure('Invalid Album cleanup receipt'); }
		$current = $cache ? $this->snapshot_cache($scope, $name) : $this->snapshot($scope, $name);
		if ($current['present'] === false) { return 'missing'; }
		if ($expected['present'] === false || !$this->same_metadata($current, $expected)) { return 'changed'; }
		$this->check($cache ? 'cache-remove' : 'remove', $scope, $name, null, $expected); $path = $this->path($scope, $name); $stat = $this->stat($path);
		if ($stat === false) { return 'missing'; }
		if ($stat['size'] !== $expected['bytes']) { return 'changed'; }
		if (!@unlink($path)) { return 'busy'; }
		if ($this->stat($path) !== false) { throw new AlbumFileStorageFailure('Album path unexpectedly reused'); }
		return 'removed';
	}

	function remove_stage($operation, $scope, $name)
	{
		$stage = $this->stage_name($operation, $scope, $name); $this->check('remove-stage', $scope, $name, $operation); $path = $this->path($scope, $stage);
		if ($this->stat($path) === false) { return 'missing'; }
		if (!@unlink($path)) { return 'busy'; }
		if ($this->stat($path) !== false) { throw new AlbumFileStorageFailure('Album stage unexpectedly reused'); }
		return 'removed';
	}
}
