<?php
namespace phpbb2plus\downloads\service;

class mirrors
{
	const PAGE_SIZE = 25;
	private $db;
	private $access;
	private $media;
	private $user;
	private $log;
	private $p;

	public function __construct($db, $access, $media, $user, $log, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->media = $media;
		$this->user = $user;
		$this->log = $log;
		$this->p = $prefix;
	}

	public function files($query, $start)
	{
		$filter = array();
		if ($query !== '')
		{
			$wild = $this->db->get_any_char();
			foreach (array_unique(array($query,utf8_htmlspecialchars($query))) as $text)
			{ $filter[] = 'f.title ' . $this->db->sql_like_expression($wild . utf8_encode_ucr($text) . $wild); }
			if (ctype_digit($query) && $query<=4294967295) { $filter[] = 'f.file_id=' . (int) $query; }
		}
		$result = $this->db->sql_query_limit('SELECT f.file_id,f.title,f.approved,c.title AS category,(SELECT COUNT(*) FROM ' .
			$this->p . 'mirrors m WHERE m.file_id=f.file_id) AS copies FROM ' . $this->p . 'files f LEFT JOIN ' . $this->p .
			'categories c ON c.cat_id=f.cat_id' . ($filter ? ' WHERE (' . implode(' OR ',$filter) . ')' : '') .
			' ORDER BY f.title,f.file_id',self::PAGE_SIZE+1,max(0,$start));
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function file($id)
	{
		$result = $this->db->sql_query('SELECT file_id,title,cat_id,review_revision FROM ' . $this->p . 'files WHERE file_id=' . (int) $id);
		$file = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$file) { throw new \DomainException('ACP_PLUS_DL_MIRROR_MISSING'); }
		// The historical file ACP could manage private or unapproved entries.
		// Creating and editing mirrors still requires a complete category ancestry.
		$cats = $this->access->categories();
		$cat = (int) $file['cat_id'];
		$seen = array();
		$file['eligible'] = $cat>0 && !empty($cats[$cat]['allow_files']);
		while ($cat && $file['eligible'])
		{
			if (isset($seen[$cat]) || !isset($cats[$cat])) { $file['eligible'] = false; break; }
			$seen[$cat] = true;
			$cat = (int) $cats[$cat]['parent_id'];
		}
		return $file;
	}

	public function items($file, $ids = array())
	{
		$sql = 'SELECT * FROM ' . $this->p . 'mirrors WHERE file_id=' . (int) $file;
		if ($ids) { $sql .= ' AND ' . $this->db->sql_in_set('mirror_id',$ids); }
		$result = $this->db->sql_query($sql . ' ORDER BY mirror_id');
		$rows = array();
		while ($row = $this->db->sql_fetchrow($result)) { $rows[(int) $row['mirror_id']] = $row; }
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public static function label($row) { return access::raw_text($row,'location','mirror_location','plus_mirror_escaped'); }
	public static function remote($source) { return (string) ($source['plus_mirror_url'] ?? $source['file_dlurl'] ?? ''); }
	public static function filename($source) { return (string) ($source['plus_mirror_name'] ?? $source['unique_name'] ?? ''); }

	public function review($action, $id, $ids)
	{
		if (!$this->access->mirror_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$file = $this->file($id);
		if (!$file['eligible'] && $action !== 'delete') { throw new \DomainException('ACP_PLUS_DL_MIRROR_CATEGORY'); }
		$rows = array();
		if ($action === 'create' && !$ids) { $rows = array(0=>null); }
		elseif (in_array($action,array('edit','delete'),true))
		{
			$ids = catalogue_selection::ids($ids,'ACP_PLUS_DL_MIRROR_SELECTION');
			$rows = $this->items($id,$ids);
			if (count($rows)!==count($ids)) { throw new \DomainException('ACP_PLUS_DL_MIRROR_CHANGED'); }
		}
		else { throw new \DomainException('FORM_INVALID'); }
		return array('file'=>$file,'rows'=>$rows,'state'=>hash('sha256',serialize(array($action,$file,$rows))));
	}

	private function changes($input, $old, $asset)
	{
		$location = $input['location'];
		if (!is_string($location) || strlen($location)>1020 || !preg_match('//u',$location) ||
			utf8_strlen($location)>255 || preg_match('/[\x00-\x1f\x7f]/',$location) || (!$old && trim($location)===''))
		{ throw new \DomainException('ACP_PLUS_DL_MIRROR_LOCATION_INVALID'); }
		$source = $old && $old['source_json'] !== '' ? json_decode($old['source_json'],true) : array();
		if (!is_array($source)) { throw new \DomainException('ACP_PLUS_DL_MIRROR_INVALID'); }
		$changed = array();
		if (!$old || $location !== self::label($old))
		{
			$changed['location'] = utf8_encode_ucr(utf8_htmlspecialchars($location));
			$source['plus_mirror_escaped'] = 1;
		}
		$url = $input['url'];
		switch ($input['source'])
		{
			case 'keep':
				if (!$old || $asset || $url !== '') { throw new \DomainException('ACP_PLUS_DL_MIRROR_SOURCE_INVALID'); }
				break;
			case 'remote':
				if ($asset || strlen($url)>1020 || utf8_strlen($url)>255 || access::external($url) === '')
				{ throw new \DomainException('ACP_PLUS_DL_MIRROR_SOURCE_INVALID'); }
				if (!$old || $old['asset_status'] !== 'remote' || $url !== self::remote($source))
				{
					$changed += array('asset_status'=>'remote','storage_name'=>'','file_bytes'=>0);
					$source['plus_mirror_url'] = $url;
					$source['plus_mirror_name'] = '';
				}
				break;
			case 'local':
				if (!$asset || $url !== '') { throw new \DomainException('ACP_PLUS_DL_MIRROR_SOURCE_INVALID'); }
				$changed += array('asset_status'=>'ready','storage_name'=>$asset['storage'],'file_bytes'=>$asset['bytes']);
				$source['plus_mirror_name'] = $asset['name'];
				$source['plus_mirror_url'] = '';
				break;
			default: throw new \DomainException('ACP_PLUS_DL_MIRROR_SOURCE_INVALID');
		}
		if ($changed) { $changed['source_json'] = json_encode($source); }
		return $changed;
	}

	public function save($action, $id, $ids, $state, $input, $uploads, $confirmed)
	{
		if (!$this->access->mirror_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$assets = array();
		try
		{
			// Reservations must survive rollback of the database changes so that
			// cleanup can remove partial copies after an interruption.
			foreach ($uploads as $key=>$file)
			{ $assets[$key] = $this->media->reserve($this->media->inspect($file,false,false)); }
			$this->db->sql_transaction('begin');
			$this->access->lock_catalogue(false);
			$this->db->sql_query('UPDATE ' . $this->p . 'files SET file_id=file_id WHERE file_id=' . (int) $id);
			if (!$this->access->mirror_admin(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$this->access->refresh();
			$view = $this->review($action,$id,$ids);
			if (!hash_equals($view['state'],$state)) { throw new \DomainException('ACP_PLUS_DL_MIRROR_CHANGED'); }
			$rows = $view['rows'];
			if ($action === 'delete')
			{
				if (!$confirmed) { throw new \DomainException('ACP_PLUS_DL_MIRROR_CONFIRM'); }
				foreach ($rows as $row) { $this->media->queue($row['storage_name']); }
				$changed = array_keys($rows);
				$this->db->sql_query('DELETE FROM ' . $this->p . 'mirrors WHERE file_id=' . (int) $id . ' AND ' . $this->db->sql_in_set('mirror_id',$changed));
				if ((int) $this->db->sql_affectedrows()!==count($changed)) { throw new \DomainException('ACP_PLUS_DL_MIRROR_CHANGED'); }
			}
			else
			{
				if (array_keys($input) !== array_keys($rows)) { throw new \DomainException('FORM_INVALID'); }
				$updates = array();
				foreach ($rows as $key=>$old) { $updates[$key] = $this->changes($input[$key],$old,$assets[$key] ?? null); }
				$changed = array();
				foreach ($updates as $key=>$data)
				{
					if (!$data) { continue; }
					$this->media->publish($assets[$key] ?? null);
					if ($action === 'create')
					{
						$data['file_id'] = (int) $id;
						$this->db->sql_query('INSERT INTO ' . $this->p . 'mirrors ' . $this->db->sql_build_array('INSERT',$data));
						$changed[] = (int) $this->db->sql_nextid();
					}
					else
					{
						$this->db->sql_query('UPDATE ' . $this->p . 'mirrors SET ' . $this->db->sql_build_array('UPDATE',$data) .
							' WHERE mirror_id=' . (int) $key . ' AND file_id=' . (int) $id);
						if (isset($data['storage_name'])) { $this->media->queue($rows[$key]['storage_name']); }
						$changed[] = $key;
					}
				}
			}
			if ($changed)
			{
				$this->db->sql_query('UPDATE ' . $this->p . 'files SET review_revision=review_revision+1 WHERE file_id=' . (int) $id);
				$this->log->add('admin',$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_DL_MIRROR_' . strtoupper($action),
					false,array((int) $id,implode(', ',$changed)));
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The mirror update did not commit.'); }
			$assets = array();
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		finally { foreach ($assets as $asset) { if ($asset) { $this->media->collect($asset['job']); } } }
	}
}
