<?php
namespace phpbb2plus\gallery\cron;

class cleanup extends \phpbb\cron\task\base
{
	private $db;
	private $config;
	private $prefix;
	private $root;

	public function __construct($db, $config, $prefix, $root)
	{
		$this->db = $db;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->root = $root;
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
			$name = $item['storage_name'];
			if (!preg_match('/^gallery_([a-f0-9]{64})$/D', $name, $match)) { continue; }
			$result = $this->db->sql_query_limit('SELECT pic_id FROM ' . $this->prefix . "pics WHERE storage_name='$name'", 1);
			$still_used = $this->db->sql_fetchfield('pic_id') !== false;
			$this->db->sql_freeresult($result);
			if ($still_used) { continue; }
			$prefix = 'gallery_cache_' . $match[1] . '_';
			$cached = glob($dir . DIRECTORY_SEPARATOR . $prefix . '*', GLOB_NOSORT);
			if ($cached === false) { continue; }
			$complete = true;
			foreach (array_merge($cached, array($dir . DIRECTORY_SEPARATOR . $name, $dir . DIRECTORY_SEPARATOR . 'gallery_small_' . $match[1])) as $path)
			{
				$file = basename($path);
				if ($file !== $name && $file !== 'gallery_small_' . $match[1] && !preg_match('/^' . $prefix . '[a-f0-9]{64}$/D', $file)) { continue; }
				if (is_link($path) || (file_exists($path) && (!is_file($path) || !@unlink($path)))) { $complete = false; }
			}
			if ($complete) { $this->db->sql_query('DELETE FROM ' . $this->prefix . "trash WHERE storage_name='$name'"); }
		}
		$lifetime = (int) $this->config['form_token_lifetime'];
		if ($lifetime > 0)
		{
			$this->db->sql_query('DELETE FROM ' . $this->prefix . 'batches WHERE created_at<' . (time() - $lifetime - 86400));
		}
		$this->config->set('plus_gallery_gc_time', time(), true);
	}
}
