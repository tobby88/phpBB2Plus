<?php
namespace phpbb2plus\community\service;

class erased_files
{
	private $db;
	private $config;
	private $root;
	private $prefix;
	private $container;
	private $pending = array();

	public function __construct($db, $config, $root, $prefix, $container)
	{
		$this->db = $db;
		$this->config = $config;
		$this->root = $root;
		$this->prefix = $prefix;
		$this->container = $container;
	}
	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}
	private function storage_file($name)
	{
		return $name !== '' && $name[0] !== '.' && strpbrk($name, "/\\:\0") === false &&
			!in_array(strtolower($name), array('index.htm', 'index.html', 'index.php', 'web.config'), true);
	}

	/** Detach only native attachments of these locked posts inside the caller's transaction. */
	public function detach($topic, $posts)
	{
		$db = $this->db;
		$where = 'topic_id=' . (int) $topic . ' AND in_message=0 AND is_orphan=0 AND ' . $db->sql_in_set('post_msg_id', $posts);
		$files = $this->rows('SELECT attach_id,physical_filename,thumbnail,filesize FROM ' . ATTACHMENTS_TABLE . " WHERE $where ORDER BY attach_id FOR UPDATE");
		if (!$files) { return; }
		$queue = $this->prefix . 'plus_erased_files';
		$sizes = array();
		foreach ($files as $file)
		{
			$name = $file['physical_filename'];
			if (!$this->storage_file($name))
			{ throw new \UnexpectedValueException('Invalid native attachment storage name'); }
			$db->sql_query('INSERT INTO ' . $queue . ' ' . $db->sql_build_array('INSERT', array('attach_id' => (int) $file['attach_id'],
				'storage_name' => $name, 'thumbnail' => (int) $file['thumbnail'])));
			$sizes[$name] = (int) $file['filesize'];
		}
		$removed = array_column($files, 'attach_id');
		$ids = $db->sql_in_set('attach_id', $removed);
		$db->sql_query('DELETE FROM ' . $this->prefix . 'plus_attach_payers WHERE ' . $ids);
		$db->sql_query('DELETE FROM ' . $this->prefix . 'plus_attach_refs WHERE in_message=0 AND ' . $ids);
		$db->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . " WHERE $where AND $ids");
		// phpBB can share the physical upload when a topic is copied.
		foreach ($this->rows('SELECT DISTINCT physical_filename FROM ' . ATTACHMENTS_TABLE . ' WHERE is_orphan=0 AND ' .
			$db->sql_in_set('physical_filename', array_keys($sizes))) as $ref) { unset($sizes[$ref['physical_filename']]); }
		if ($sizes)
		{
			$usage = array_column($this->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE .
				" WHERE config_name IN ('num_files','upload_dir_size') ORDER BY config_name FOR UPDATE"), 'config_value', 'config_name');
			$this->config->set('num_files', max(0, (int) $usage['num_files'] - count($sizes)), false);
			$this->config->set('upload_dir_size', max(0, (int) $usage['upload_dir_size'] - array_sum($sizes)), false);
		}
		$this->config->set('plus_erased_files_due', time(), false);
		$this->pending = array_merge($this->pending, $removed);
	}

	public function after_request()
	{
		if (!$this->pending || $this->db->get_transaction()) { return; }
		foreach (array_chunk($this->pending, 100) as $ids) { $this->drain($ids); }
		$this->pending = array();
	}
	public function available()
	{
		$due = (int) ($this->config['plus_erased_files_due'] ?? 0);
		return $due > 0 && $due <= time();
	}
	public function drain($selected = array())
	{
		$db = $this->db;
		if ($db->get_transaction()) { return; }
		$table = $this->prefix . 'plus_erased_files';
		$where = 'retry_at<=' . time() . ($selected ? ' AND ' . $db->sql_in_set('attach_id', $selected) : '');
		$r = $db->sql_query_limit("SELECT attach_id FROM $table WHERE $where ORDER BY retry_at,attach_id", 100);
		$ids = array_column($db->sql_fetchrowset($r), 'attach_id');
		$db->sql_freeresult($r);
		foreach ($ids as $id)
		{
			$db->sql_transaction('begin');
			try
			{
				$files = $this->rows("SELECT storage_name,thumbnail FROM $table WHERE attach_id=" . (int) $id . ' AND retry_at<=' . time() . ' FOR UPDATE');
				if ($files)
				{
					$name = $files[0]['storage_name'];
					$in_use = $this->rows('SELECT attach_id FROM ' . ATTACHMENTS_TABLE . " WHERE physical_filename='" . $db->sql_escape($name) . "' LIMIT 1 FOR UPDATE");
					$done = $this->storage_file($name);
					if (!$in_use && $done)
					{
						$manager = $this->container->get('attachment.manager');
						foreach ($files[0]['thumbnail'] ? array('file', 'thumbnail') : array('file') as $mode)
						{
							$path = $this->root . $this->config['upload_path'] . '/' . ($mode === 'thumbnail' ? 'thumb_' : '') . $name;
							clearstatcache(true, $path);
							if (is_link($path) || (file_exists($path) && (!is_file($path) || !$manager->unlink($name, $mode, true)))) { $done = false; }
						}
					}
					$db->sql_query(($done ? "DELETE FROM $table" : "UPDATE $table SET retry_at=" . (time() + 300)) . ' WHERE attach_id=' . (int) $id);
				}
				$db->sql_transaction('commit');
			}
			catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
		}
		$db->sql_transaction('begin');
		try
		{
			// A concurrent confirmation schedules the queue in its transaction too.
			$this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_erased_files_due' FOR UPDATE");
			$next = $this->rows("SELECT MIN(retry_at) AS due FROM $table");
			$this->config->set('plus_erased_files_due', $next[0]['due'] === null ? 0 : max(1, (int) $next[0]['due']), false);
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	}
}
