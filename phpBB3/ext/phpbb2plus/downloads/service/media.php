<?php
namespace phpbb2plus\downloads\service;

class media
{
	private $access;
	private $db;
	private $config;
	private $prefix;
	private $root;

	public function __construct($access, $db, $config, $prefix, $root)
	{
		$this->access = $access;
		$this->db = $db;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->root = $root;
	}

	public function inspect($file, $preview, $digest = true)
	{
		if (!$file || (isset($file['error']) && $file['error'] === UPLOAD_ERR_NO_FILE)) { return null; }
		if (!isset($file['error'], $file['tmp_name'], $file['name']) || $file['error'] !== UPLOAD_ERR_OK ||
			!is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || !is_string($file['name']))
		{ throw new \DomainException('PLUS_DL_BAD_UPLOAD'); }
		$name = $file['name'];
		if (strlen($name) > 255 || !preg_match('//u', $name) || $name === '' || trim($name, '. ') !== $name ||
			preg_match('~[/\\\\:\x00-\x1f\x7f]~', $name)) { throw new \DomainException('PLUS_DL_BAD_UPLOAD'); }
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$this->check_extension($extension);
		$bytes = filesize($file['tmp_name']);
		$this->check_size($bytes);
		$mime = '';
		if ($preview)
		{
			$types = array('jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'gif'=>'image/gif');
			$image = @getimagesize($file['tmp_name']);
			if (!$image || !isset($types[$extension]) || $types[$extension] !== $image['mime']) { throw new \DomainException('PLUS_DL_BAD_PREVIEW'); }
			$mime = $image['mime'];
		}
		$hash = $digest ? hash_file('sha256', $file['tmp_name']) : '';
		if ($hash === false) { throw new \DomainException('PLUS_DL_BAD_UPLOAD'); }
		return array('name'=>$name, 'bytes'=>$bytes, 'path'=>$file['tmp_name'], 'hash'=>$hash, 'mime'=>$mime);
	}

	private function check_size($bytes)
	{
		if (!is_int($bytes) || $bytes < 1 || $bytes > 2147483647 ||
			(!$this->access->admin() && $bytes > (int) $this->access->setting('max_file_size', 262144)))
		{ throw new \DomainException('PLUS_DL_BAD_SIZE'); }
	}

	private function check_extension($extension)
	{
		$forbidden = array_map('trim', explode(',', strtolower($this->access->setting('forbidden_extensions', ''))));
		if (!preg_match('/^[a-z0-9]{1,16}$/D', $extension) || preg_match('/^php[0-9]*$/D', $extension) ||
			in_array($extension, array_merge($forbidden, array('phtml','phar','cgi','pl','py','sh','shtml','shtm','asp','aspx','jsp')), true))
		{ throw new \DomainException('PLUS_DL_BAD_EXTENSION'); }
	}

	public function queue($name)
	{
		if (!self::owned_name($name)) { return 0; }
		$this->db->sql_query('INSERT INTO ' . $this->prefix . 'media_queue ' . $this->db->sql_build_array('INSERT',
			array('storage_name'=>$name, 'queued_at'=>time())));
		return (int) $this->db->sql_nextid();
	}

	public static function owned_name($name) { return is_string($name) && preg_match('/^download_[a-f0-9]{64}$/D', $name) === 1; }

	public function reserve($image)
	{
		if (!$image) { return null; }
		$name = 'download_' . bin2hex(random_bytes(32));
		return $image + array('storage'=>$name, 'job'=>$this->queue($name));
	}

	public function publish($asset)
	{
		if (!$asset) { return; }
		$this->check_extension(strtolower(pathinfo($asset['name'], PATHINFO_EXTENSION)));
		$this->check_size($asset['bytes']);
		$path = $this->root . trim($this->config['upload_path'], '/') . '/' . $asset['storage'];
		// Removing the reservation inside the file transaction holds its lock until commit.
		// On rollback or interruption cron can still find and discard any partial copy.
		$this->db->sql_query('DELETE FROM ' . $this->prefix . 'media_queue WHERE job_id=' . (int) $asset['job']);
		if ($this->db->sql_affectedrows() !== 1 || file_exists($path) || is_link($path) || !@move_uploaded_file($asset['path'], $path) ||
			@filesize($path) !== $asset['bytes']) { throw new \DomainException('PLUS_DL_STORAGE_UNAVAILABLE', 503); }
	}

	public function collect($id)
	{
		$table = $this->prefix . 'media_queue';
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(false);
			$where = ' WHERE job_id=' . (int) $id;
			$this->db->sql_query("UPDATE $table SET job_id=job_id" . $where);
			$result = $this->db->sql_query("SELECT storage_name FROM $table" . $where);
			$name = $this->db->sql_fetchfield('storage_name');
			$this->db->sql_freeresult($result);
			if (self::owned_name($name))
			{
				$files = $this->prefix . 'files';
				$result = $this->db->sql_query_limit("SELECT file_id FROM $files WHERE storage_name='$name' OR preview_name='$name'
					UNION ALL SELECT file_id FROM " . $this->prefix . "mirrors WHERE storage_name='$name'", 1);
				$used = $this->db->sql_fetchfield('file_id') !== false;
				$this->db->sql_freeresult($result);
				$path = $this->root . trim($this->config['upload_path'], '/') . '/' . $name;
				if ($used || (!file_exists($path) && !is_link($path)) || (!is_link($path) && is_file($path) && @unlink($path)))
				{ $this->db->sql_query("DELETE FROM $table" . $where); }
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}
}
