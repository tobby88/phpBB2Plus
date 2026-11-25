<?php
namespace phpbb2plus\downloads\cron;

class cleanup extends \phpbb\cron\task\base
{
	private $media;
	private $db;
	private $config;
	private $prefix;

	public function __construct($media, $db, $config, $prefix)
	{
		$this->media = $media;
		$this->db = $db;
		$this->config = $config;
		$this->prefix = $prefix;
	}
	public function should_run() { return (int) $this->config['plus_dl_cleanup_time'] + 3600 < time(); }

	public function run()
	{
		$result = $this->db->sql_query_limit('SELECT job_id FROM ' . $this->prefix . 'media_queue WHERE queued_at<' . (time() - 3600) . ' ORDER BY queued_at,job_id', 100);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		foreach ($rows as $row) { $this->media->collect($row['job_id']); }
		$lifetime = (int) $this->config['form_token_lifetime'];
		if ($lifetime > 0) { $this->db->sql_query('DELETE FROM ' . $this->prefix . 'receipts WHERE created_at<' . (time() - $lifetime - 86400)); }
		$this->config->set('plus_dl_cleanup_time', time(), true);
	}
}
