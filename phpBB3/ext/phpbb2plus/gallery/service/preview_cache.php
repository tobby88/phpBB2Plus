<?php
namespace phpbb2plus\gallery\service;

class preview_cache
{
	const GENERATION = 'plus_gallery_cache_generation';
	const DUE = 'plus_gallery_cache_cleanup_after';
	const GRACE = 3600;
	private $db;
	private $config;
	private $access;
	private $admin;
	private $user;
	private $log;

	public function __construct($db, $config, $access, $admin, $user, $log)
	{
		$this->db = $db;
		$this->config = $config;
		$this->access = $access;
		$this->admin = $admin;
		$this->user = $user;
		$this->log = $log;
	}

	public static function filename($name, $variant, $generation)
	{
		return 'gallery_cache_' . substr($name, 8) . '_' . ($generation > 0 ? (int) $generation . '_' : '') . $variant;
	}

	public static function generation($filename, $source_hash = null)
	{
		// The original format, without a generation, remains readable until the first purge.
		if (!preg_match('/^gallery_cache_([a-f0-9]{64})_(?:([1-9][0-9]{0,11})_)?[a-f0-9]{64}$/D', $filename, $parts) ||
			($source_hash !== null && $parts[1] !== $source_hash)) { return null; }
		return isset($parts[2]) ? (int) $parts[2] : 0;
	}

	public static function remove_regular($path)
	{
		clearstatcache(true, $path);
		if (is_link($path)) { return false; }
		if (!file_exists($path)) { return true; }
		return is_file($path) && @unlink($path);
	}

	private function state()
	{
		$result = $this->db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' .
			$this->db->sql_in_set('config_name', array(self::GENERATION, self::DUE)));
		$settings = array_column($this->db->sql_fetchrowset($result), 'config_value', 'config_name');
		$this->db->sql_freeresult($result);
		if (!isset($settings[self::GENERATION], $settings[self::DUE])) { throw new \RuntimeException('Missing gallery cache settings'); }
		return array((int) $settings[self::GENERATION], (int) $settings[self::DUE]);
	}

	public function invalidate()
	{
		$this->access->write(function () {
			$this->admin->authorize('cache', true);
			list($generation) = $this->state();
			$this->config->set(self::GENERATION, max(time(), $generation + 1), false);
			$this->config->set(self::DUE, time() + self::GRACE, false);
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_PLUS_ALBUM_CACHE');
		});
	}

	public function collect($directory)
	{
		$due = (int) ($this->config[self::DUE] ?? 0);
		if (!$due || $due > time()) { return; }
		list($generation, $due) = $this->state();
		if (!$generation || !$due || $due > time()) { return; }
		$handle = @opendir($directory);
		if (!$handle) { return; }
		$complete = true;
		try
		{
			while (($file = readdir($handle)) !== false)
			{
				$version = self::generation($file);
				if ($version === null || $version >= $generation) { continue; }
				$path = $directory . DIRECTORY_SEPARATOR . $file;
				if (is_link($path) || !is_file($path)) { continue; }
				if (!self::remove_regular($path)) { $complete = false; }
			}
		}
		finally { closedir($handle); }
		if ($complete)
		{
			$this->access->write(function () use ($generation, $due) {
				// A second purge can take place during the directory walk.
				if ($this->state() === array($generation, $due)) { $this->config->set(self::DUE, 0, false); }
			});
		}
	}
}
