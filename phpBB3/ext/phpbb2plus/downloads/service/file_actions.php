<?php
namespace phpbb2plus\downloads\service;

class file_actions
{
	private $db;
	private $access;
	private $submissions;
	private $user;
	private $log;
	private $prefix;

	public function __construct($db, $access, $submissions, $user, $log, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->submissions = $submissions;
		$this->user = $user;
		$this->log = $log;
		$this->prefix = $prefix;
	}

	public function review($action, $ids, $lock = false)
	{
		if (!$this->access->file_admin($action)) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$ids = catalogue_selection::ids($ids,'ACP_PLUS_DL_FILE_SELECTION');
		$where = ' WHERE ' . $this->db->sql_in_set('file_id',$ids);
		$files = $this->prefix . 'files';
		// Catalogue writers use the same lock and public changes lock the file row.
		// Lock in primary-key order before checking the selection or its related data.
		$current = $lock && in_array($this->db->get_sql_layer(),array('mysql','mysqli'),true) ? ' FOR UPDATE' : '';
		if ($lock && !$current) { $this->db->sql_query("UPDATE $files SET file_id=file_id" . $where); }
		$result = $this->db->sql_query('SELECT file_id,cat_id,title,owner_id,license_id,approved,broken,review_revision FROM ' .
			$files . $where . ' ORDER BY file_id' . $current);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if (count($rows)!==count($ids)) { throw new \DomainException('ACP_PLUS_DL_FILE_CHANGED'); }
		$categories = $this->access->categories();
		$changed = array();
		$hash = hash_init('sha256');
		hash_update($hash,$action);
		foreach ($rows as &$row)
		{
			$row['category'] = $categories[(int) $row['cat_id']]['title'] ?? '';
			hash_update($hash,serialize($row));
			// The old ACP allowed a mixed selection and left matching rows as they were.
			if ($action === 'delete' || (bool) $row['approved'] !== ($action === 'approve')) { $changed[] = (int) $row['file_id']; }
		}
		unset($row);
		$counts = $action === 'delete' ? $this->submissions->removal_details($ids,$hash,$lock) : array();
		return array('rows'=>$rows,'state'=>hash_final($hash),'changed'=>$changed,'counts'=>$counts);
	}

	public function save($action, $ids, $state, $confirmed)
	{
		if (!$confirmed) { throw new \DomainException('ACP_PLUS_DL_FILE_CONFIRM_REQUIRED'); }
		if (!$this->access->file_admin($action)) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(false);
			if (!$this->access->file_admin($action,true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$this->access->refresh();
			$view = $this->review($action,$ids,true);
			if (!hash_equals($view['state'],$state)) { throw new \DomainException('ACP_PLUS_DL_FILE_CHANGED'); }
			if ($view['changed'])
			{
				$this->submissions->apply_file_action($action,$view['changed']);
				$this->log->add('admin',$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_DL_FILES_' . strtoupper($action),
					false,array(implode(', ',$view['changed'])));
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download selection did not commit.'); }
			return count($view['changed']);
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}
}
