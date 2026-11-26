<?php
namespace phpbb2plus\downloads\service;

class file_checker
{
	private $access;
	private $db;
	private $config;
	private $p;
	private $root;

	public function __construct($access, $db, $config, $prefix, $root)
	{
		$this->access = $access;
		$this->db = $db;
		$this->config = $config;
		$this->p = $prefix;
		$this->root = $root;
	}

	private function inventory()
	{
		$base = realpath($this->root);
		$dir = $base;
		$relative = rtrim(str_replace('\\', '/', $this->config['upload_path']), '/');
		if ($dir === false || $relative === '' || $relative[0] === '/' || preg_match('/[:\x00-\x1f\x7f]/', $relative))
		{ throw new \DomainException('ACP_PLUS_DL_CHECK_UNAVAILABLE'); }
		foreach (explode('/', $relative) as $part)
		{
			if ($part === '.') { continue; }
			if ($part === '' || $part === '..') { throw new \DomainException('ACP_PLUS_DL_CHECK_UNAVAILABLE'); }
			$dir .= '/' . $part;
			if (is_link($dir) || !is_dir($dir)) { throw new \DomainException('ACP_PLUS_DL_CHECK_UNAVAILABLE'); }
		}
		$resolved = realpath($dir);
		$expected = str_replace('\\', '/', $dir);
		$actual = str_replace('\\', '/', (string) $resolved);
		$same_path = DIRECTORY_SEPARATOR === '\\' ? strcasecmp($expected, $actual) === 0 : $expected === $actual;
		if ($dir === $base || !$same_path || !($handle = @opendir($dir))) { throw new \DomainException('ACP_PLUS_DL_CHECK_UNAVAILABLE'); }
		$files = array();
		try
		{
			while (($name = readdir($handle)) !== false)
			{
				if (!media::owned_name($name)) { continue; }
				$path = $dir . '/' . $name;
				clearstatcache(true, $path);
				$stat = @lstat($path);
				if ($stat === false) { throw new \DomainException('ACP_PLUS_DL_CHECK_UNAVAILABLE'); }
				if (($stat['mode'] & 0170000) === 0100000 && !is_link($path)) { $files[$name] = $stat['size']; }
			}
		}
		finally { closedir($handle); }
		return $files;
	}

	private function each($sql, callable $read)
	{
		$r = $this->db->sql_query($sql);
		try { while ($row = $this->db->sql_fetchrow($r)) { $read($row); } }
		finally { $this->db->sql_freeresult($r); }
	}

	public function report()
	{
		if (!$this->access->checker_admin(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$this->db->sql_transaction('begin');
		try
		{
			// Upload, replacement, removal and garbage collection share this catalogue lock.
			$this->access->lock_catalogue(true);
			$physical = $this->inventory();
			$unused = $physical;
			$issues = array();
			$check = static function ($kind, $id, $title, $name, $status, $bytes = null) use (&$issues, &$unused, $physical) {
				unset($unused[$name]); // A reference of any status protects an existing object.
				if ($status === 'remote') { return; }
				if ($status !== 'ready') { $reason = $status === 'review' ? 'REVIEW' : 'IMPORT'; }
				elseif (!isset($physical[$name])) { $reason = 'MISSING'; }
				elseif ($bytes !== null && (string) $physical[$name] !== (string) $bytes) { $reason = 'SIZE'; }
				else { return; }
				$issues[] = array('kind'=>$kind, 'id'=>(int) $id, 'title'=>access::plain($title), 'reason'=>$reason);
			};
			$this->each("SELECT file_id,title,storage_name,asset_status,file_bytes,preview_name,preview_unavailable FROM {$this->p}files ORDER BY file_id",
				static function ($row) use ($check) {
					$check('FILE', $row['file_id'], $row['title'], $row['storage_name'], $row['asset_status'], $row['file_bytes']);
					if ($row['preview_name'] !== '' || $row['preview_unavailable'])
					{
						$check('PREVIEW', $row['file_id'], $row['title'], $row['preview_name'], $row['preview_name'] !== '' ? 'ready' : 'missing');
					}
				});
			$this->each("SELECT mirror_id,file_id,location,storage_name,asset_status,file_bytes FROM {$this->p}mirrors ORDER BY mirror_id",
				static function ($row) use ($check) {
					$check('MIRROR', $row['file_id'], '#' . $row['mirror_id'] . ' ' . $row['location'], $row['storage_name'], $row['asset_status'], $row['file_bytes']);
				});
			$this->each("SELECT storage_name FROM {$this->p}media_queue", static function ($row) use (&$unused) { unset($unused[$row['storage_name']]); });
			// The protected upload folder also holds native attachments, including converted physical names.
			$this->each('SELECT physical_filename FROM ' . ATTACHMENTS_TABLE . ' WHERE physical_filename ' .
				$this->db->sql_like_expression('download_' . $this->db->get_any_char()), static function ($row) use (&$unused) { unset($unused[$row['physical_filename']]); });
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		if (!$this->access->checker_admin(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
		ksort($unused, SORT_STRING);
		return array('issues'=>$issues, 'unreferenced'=>$unused, 'bytes'=>array_sum($unused));
	}
}
