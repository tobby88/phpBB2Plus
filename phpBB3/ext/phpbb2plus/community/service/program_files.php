<?php
namespace phpbb2plus\community\service;

class program_files
{
	private const CATALOG_HASH = '__PLUS_PROGRAM_CATALOG_SHA256__';
	private $root;
	private $catalog;
	public function __construct($root) { $this->root = realpath($root); }

	public static function program_name($path)
	{
		if (!is_string($path) || strlen($path) > 255 || substr($path, -4) !== '.php' ||
			!preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~D', $path)) { return false; }
		$parts = explode('/', $path); $name = strtolower(array_pop($parts));
		return (!$parts || in_array($parts[0], array('adm','bin','download','ext','includes','language','phpbb','vendor'), true)) &&
			!array_intersect(array_map('strtolower', $parts), array('config','cache','files','store','uploads','private','logs','fixtures','tests','examples')) &&
			!in_array($name, array('config.php','local.php','credentials.php','secrets.php','parameters.php'), true) && substr($name, -10) !== '.local.php';
	}

	/** A missing packaged path is distinct from an unreadable or redirected one. */
	private function open($relative)
	{
		if ($this->root === false) { return false; }
		$parts = explode('/', $relative); $path = $this->root;
		foreach ($parts as $i => $part)
		{
			$parent = $path; $path .= DIRECTORY_SEPARATOR . $part;
			clearstatcache(true, $path);
			if (is_link($path)) { return false; }
			$stat = @lstat($path);
			if (!$stat) { return is_readable($parent) && (DIRECTORY_SEPARATOR === '\\' || is_executable($parent)) ? null : false; }
			if (realpath($path) !== $path) { return false; }
			if ($i < count($parts) - 1 && ($stat['mode'] & 0170000) !== 0040000) { return false; }
		}
		if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] > 1) { return false; }
		$file = @fopen($path, 'rb'); if (!$file) { return false; }
		$opened = fstat($file);
		clearstatcache(true, $path);
		if (!$opened || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] || $opened['nlink'] > 1 ||
			($opened['mode'] & 0170000) !== 0100000 || realpath($path) !== $path || is_link($path))
		{
			fclose($file); return false;
		}
		return $file;
	}

	public function catalog()
	{
		if ($this->catalog !== null) { return $this->catalog; }
		$file = $this->open('ext/phpbb2plus/community/data/program-files.php');
		if (!is_resource($file)) { throw new \DomainException('PLUS_CT_FILES_CATALOG'); }
		try { $raw = stream_get_contents($file, 8388609); }
		finally { fclose($file); }
		$guard = "<?php exit; ?>\n";
		if ($raw === false || strlen($raw) > 8388608 || substr($raw, 0, strlen($guard)) !== $guard ||
			!hash_equals(self::CATALOG_HASH, hash('sha256', $raw))) { throw new \DomainException('PLUS_CT_FILES_CATALOG'); }
		$data = json_decode(substr($raw, strlen($guard)), true);
		if (!is_array($data) || empty($data['paths']) || !is_array($data['paths']) || count($data['paths']) > 30000 ||
			!preg_match('/^[a-f0-9]{64}$/D', $data['package'] ?? '')) { throw new \DomainException('PLUS_CT_FILES_CATALOG'); }
		$previous = '';
		foreach ($data['paths'] as $path)
		{
			if (!self::program_name($path) || strcmp($previous, $path) >= 0) { throw new \DomainException('PLUS_CT_FILES_CATALOG'); }
			$previous = $path;
		}
		return $this->catalog = $data;
	}

	/** The caller supplies only an offset into the sealed package catalogue. */
	public function sample($offset, $count)
	{
		$paths = array_slice($this->catalog()['paths'], max(0, $offset), min(400, max(0, $count)));
		$sample = array();
		$deadline = microtime(true) + 1.5;
		foreach ($paths as $path)
		{
			if ($sample && microtime(true) >= $deadline) { break; }
			$file = $this->open($path);
			if (!is_resource($file)) { $sample[$path] = $file; continue; }
			try
			{
				$before = fstat($file);
				$hash = hash_init('sha256');
				$length = $before ? @hash_update_stream($hash, $file, $before['size']) : -1;
				$after = fstat($file);
				$name = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
				clearstatcache(true, $name);
				$current = @lstat($name);
				$stable = $before && $after && $current && $length === $before['size'] &&
					($current['mode'] & 0170000) === 0100000 && realpath($name) === $name && !is_link($name) &&
					$current['dev'] === $before['dev'] && $current['ino'] === $before['ino'] && $current['nlink'] <= 1 &&
					$current['size'] === $after['size'] && $after['size'] === $before['size'] &&
					$current['mtime'] === $before['mtime'] && $after['mtime'] === $before['mtime'] &&
					$current['ctime'] === $before['ctime'] && $after['ctime'] === $before['ctime'];
				$sample[$path] = $stable ? hash_final($hash) : false;
			}
			finally { fclose($file); }
		}
		return $sample;
	}
}
