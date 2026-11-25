<?php
namespace phpbb2plus\gallery\cron;

use phpbb2plus\gallery\service\preview_cache;

class cleanup extends \phpbb\cron\task\base
{
	private $db;
	private $config;
	private $prefix;
	private $root;
	private $access;
	private $cache;

	public function __construct($db, $config, $prefix, $root, $access, $cache)
	{
		$this->db = $db;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->access = $access;
		$this->cache = $cache;
	}

	public function should_run()
	{
		return (int) $this->config['plus_gallery_gc_time'] + 3600 < time();
	}

	public function run()
	{
		$dir = realpath($this->root . $this->config['upload_path']);
		if (!$dir || !is_dir($dir)) { return; }
		// Allow image responses already in flight when a picture was deleted to finish.
		$cut = time() - 3600;
		$result = $this->db->sql_query_limit('SELECT storage_name FROM ' . $this->prefix . 'trash WHERE removed_at<' . $cut . ' ORDER BY removed_at', 100);
		$items = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		foreach ($items as $item)
		{
			$this->access->write(function () use ($dir, $cut, $item) { $this->collect($dir, $cut, $item['storage_name']); });
		}
		$this->cache->collect($dir);
		$lifetime = (int) $this->config['form_token_lifetime'];
		if ($lifetime > 0)
		{
			$this->db->sql_query('DELETE FROM ' . $this->prefix . 'batches WHERE created_at<' . (time() - $lifetime - 86400));
		}
		$this->config->set('plus_gallery_gc_time', time(), true);
	}

	private function collect($dir, $cut, $name)
	{
		if (!preg_match('/^gallery_([a-f0-9]{64})$/D', $name, $match)) { return; }
		$table = $this->prefix . 'trash';
		$result = $this->db->sql_query("SELECT storage_name FROM $table WHERE storage_name='$name' AND removed_at<$cut");
		$expired = $this->db->sql_fetchfield('storage_name') !== false;
		$this->db->sql_freeresult($result);
		if (!$expired) { return; } // A concurrent retry renewed or committed this reservation.
		$result = $this->db->sql_query_limit('SELECT pic_id FROM ' . $this->prefix . "pics WHERE storage_name='$name'", 1);
		$still_used = $this->db->sql_fetchfield('pic_id') !== false;
		$this->db->sql_freeresult($result);
		if (!$still_used)
		{
			$prefix = 'gallery_cache_' . $match[1] . '_';
			$cached = glob($dir . DIRECTORY_SEPARATOR . $prefix . '*', GLOB_NOSORT);
			if ($cached === false) { return; }
			$small = 'gallery_small_' . $match[1];
			$owned = array($name, $small, $name . '.part', $small . '.part');
			foreach ($owned as $file) { $cached[] = $dir . DIRECTORY_SEPARATOR . $file; }
			foreach ($cached as $path)
			{
				$file = basename($path);
				if (!in_array($file, $owned, true) && preview_cache::generation($file, $match[1]) === null) { continue; }
				if (!preview_cache::remove_regular($path)) { return; }
			}
		}
		$this->db->sql_query("DELETE FROM $table WHERE storage_name='$name'");
	}
}
