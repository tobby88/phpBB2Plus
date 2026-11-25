<?php
namespace phpbb2plus\downloads\service;

class licenses
{
	// Converted text and its original source can each be the size of a full document.
	private const DOCUMENT_BATCH = 25;

	private $db;
	private $access;
	private $user;
	private $log;
	private $prefix;

	public function __construct($db, $access, $user, $log, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->user = $user;
		$this->log = $log;
		$this->prefix = $prefix;
	}

	public static function selection($values)
	{
		return catalogue_selection::ids($values,'ACP_PLUS_DL_LICENSE_SELECTION');
	}

	public function listing()
	{
		$result = $this->db->sql_query('SELECT l.license_id,(SELECT COUNT(*) FROM ' . $this->prefix .
			'files f WHERE f.license_id=l.license_id) AS uses_count FROM ' . $this->prefix . 'licenses l ORDER BY l.title,l.license_id');
		$list = array();
		while ($r = $this->db->sql_fetchrow($result))
		{
			$id = (int) $r['license_id'];
			$list[$id] = array('id'=>$id,'uses'=>(int) $r['uses_count']);
		}
		$this->db->sql_freeresult($result);
		foreach (array_chunk(array_keys($list),self::DOCUMENT_BATCH) as $batch)
		{
			$result = $this->db->sql_query('SELECT license_id,title,source_json FROM ' . $this->prefix .
				'licenses WHERE ' . $this->db->sql_in_set('license_id',$batch));
			while ($row = $this->db->sql_fetchrow($result))
			{ $list[(int) $row['license_id']]['title'] = license_text::raw($row,'title'); }
			$this->db->sql_freeresult($result);
		}
		return array_filter($list,static function ($row) { return isset($row['title']); });
	}

	public function review($action, $ids)
	{
		if (!$this->access->license_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		if ($action === 'create')
		{
			if ($ids) { throw new \DomainException('FORM_INVALID'); }
			$batches = array(array());
		}
		else
		{
			$ids = self::selection($ids);
			if (($action !== 'edit' && $action !== 'delete') || ($action === 'edit' && count($ids)!==1)) { throw new \DomainException('FORM_INVALID'); }
			$batches = array_chunk($ids,self::DOCUMENT_BATCH);
		}
		$hash = hash_init('sha256');
		hash_update($hash,$action);
		$rows = array();
		foreach ($batches as $batch)
		{
			$sql = $batch ? 'SELECT license_id,title,terms,source_json FROM ' . $this->prefix .
				'licenses WHERE ' . $this->db->sql_in_set('license_id',$batch) : 'SELECT license_id FROM ' . $this->prefix . 'licenses';
			$result = $this->db->sql_query($sql . ' ORDER BY license_id');
			while ($row = $this->db->sql_fetchrow($result))
			{
				hash_update($hash,serialize($row));
				if ($action === 'create') { continue; }
				$id = (int) $row['license_id'];
				$rows[$id] = array('title'=>license_text::raw($row,'title'),'uses'=>0);
				if ($action === 'edit') { $rows[$id] += array('terms'=>license_text::raw($row,'terms'),'source_json'=>$row['source_json']); }
			}
			$this->db->sql_freeresult($result);
		}
		if ($action !== 'create')
		{
			if (count($rows)!==count($ids)) { throw new \DomainException('ACP_PLUS_DL_LICENSE_CHANGED'); }
			$result = $this->db->sql_query('SELECT file_id,license_id FROM ' . $this->prefix . 'files WHERE ' .
				$this->db->sql_in_set('license_id',$ids) . ' ORDER BY license_id,file_id');
			while ($file = $this->db->sql_fetchrow($result))
			{
				hash_update($hash,serialize($file));
				++$rows[(int) $file['license_id']]['uses'];
			}
			$this->db->sql_freeresult($result);
		}
		return array('rows'=>$rows,'state'=>hash_final($hash));
	}

	private function document($name, $terms, $source)
	{
		foreach (array($name,$terms) as $field)
		{
			if (!is_string($field) || strlen($field)>65535 || !preg_match('//u',$field) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$field))
			{ throw new \DomainException('ACP_PLUS_DL_LICENSE_INVALID'); }
		}
		$name = trim($name);
		if ($name === '') { throw new \DomainException('ACP_PLUS_DL_LICENSE_INVALID'); }
		$metadata = $source === '' ? array() : json_decode($source,true);
		if (!is_array($metadata)) { throw new \DomainException('ACP_PLUS_DL_LICENSE_INVALID'); }
		// Keep historical source fields intact. Escaping once before the native UCR
		// conversion makes even literal numeric character references reversible.
		$metadata['plus_license_escaped'] = 1;
		return array('title'=>utf8_encode_ucr(utf8_htmlspecialchars($name)),'terms'=>utf8_encode_ucr(utf8_htmlspecialchars($terms)),
			'source_json'=>json_encode($metadata));
	}

	public function save($action, $ids, $state, $name, $terms, $confirmed)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(true);
			if (!$this->access->license_admin(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			if ($action !== 'create') { $ids = self::selection($ids); }
			$view = $this->review($action,$ids);
			if (!hash_equals($view['state'],$state)) { throw new \DomainException('ACP_PLUS_DL_LICENSE_CHANGED'); }
			$table = $this->prefix . 'licenses';
			$changed = true;
			if ($action === 'delete')
			{
				if (!$confirmed) { throw new \DomainException('ACP_PLUS_DL_LICENSE_CONFIRM'); }
				$where = $this->db->sql_in_set('license_id',$ids);
				$this->db->sql_query('UPDATE ' . $this->prefix . 'files SET license_id=0,review_revision=review_revision+1 WHERE ' . $where);
				$this->db->sql_query("DELETE FROM $table WHERE $where");
				if ((int) $this->db->sql_affectedrows() !== count($ids)) { throw new \DomainException('ACP_PLUS_DL_LICENSE_CHANGED'); }
			}
			elseif ($action === 'create')
			{
				$data = $this->document($name,$terms,'{}');
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',$data));
				$ids = array((int) $this->db->sql_nextid());
			}
			else
			{
				$id = $ids[0];
				$original = $view['rows'][$id];
				$data = $this->document($name,$terms,$original['source_json']);
				$changed = trim($name) !== $original['title'] || $terms !== $original['terms'];
				if ($changed) { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$data) . " WHERE license_id=$id"); }
			}
			if ($changed)
			{ $this->log->add('admin',$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_DL_LICENSE_' . strtoupper($action),false,array(implode(', ',$ids))); }
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download licence update did not commit.'); }
		}
		catch (\Throwable $e)
		{
			if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
			throw $e;
		}
	}
}
