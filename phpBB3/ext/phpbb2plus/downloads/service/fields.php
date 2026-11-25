<?php
namespace phpbb2plus\downloads\service;

class fields
{
	private $db;
	private $access;
	private $user;
	private $log;
	private $p;

	public function __construct($db, $access, $user, $log, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->user = $user;
		$this->log = $log;
		$this->p = $prefix;
	}

	public function listing()
	{
		$result = $this->db->sql_query('SELECT d.field_id,d.title,d.position,d.field_type,(SELECT COUNT(*) FROM ' . $this->p .
			'field_values v WHERE v.field_id=d.field_id) AS entries FROM ' . $this->p . 'fields d ORDER BY d.position,d.field_id');
		$list = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $list;
	}

	public function review($action, $ids, $type)
	{
		if (!$this->access->field_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$fields = array();
		$original = null;
		$hash = hash_init('sha256');
		hash_update($hash,$action);
		if ($action === 'create')
		{
			if ($ids || $type<0 || $type>5) { throw new \DomainException('FORM_INVALID'); }
			hash_update($hash,(string) $type);
			$result = $this->db->sql_query('SELECT field_id FROM ' . $this->p . 'fields ORDER BY field_id');
			while ($row = $this->db->sql_fetchrow($result)) { hash_update($hash,serialize($row)); }
			$this->db->sql_freeresult($result);
		}
		else
		{
			$ids = catalogue_selection::ids($ids,'ACP_PLUS_DL_FIELD_SELECTION');
			if (!in_array($action,array('edit','delete'),true) || ($action === 'edit' && count($ids)!==1)) { throw new \DomainException('FORM_INVALID'); }
			foreach (array_chunk($ids,25) as $batch)
			{
				$result = $this->db->sql_query('SELECT * FROM ' . $this->p . 'fields WHERE ' . $this->db->sql_in_set('field_id',$batch) . ' ORDER BY field_id');
				while ($row = $this->db->sql_fetchrow($result))
				{
					hash_update($hash,serialize($row));
					$fields[(int) $row['field_id']] = array('title'=>access::plain($row['title']),'entries'=>0);
					if ($action === 'edit') { $original = $row; }
				}
				$this->db->sql_freeresult($result);
			}
			if (count($fields)!==count($ids)) { throw new \DomainException('ACP_PLUS_DL_FIELD_CHANGED'); }
			if ($original && (int) $original['field_type']>5) { throw new \DomainException('ACP_PLUS_DL_FIELD_UNKNOWN'); }
			if ($action === 'delete')
			{
				// A normal change to a file value replaces its row with a fresh ID.
				// Confirm the exact assignments without loading every file's field contents.
				$result = $this->db->sql_query('SELECT row_id,file_id,field_id FROM ' . $this->p . 'field_values WHERE ' .
					$this->db->sql_in_set('field_id',$ids) . ' ORDER BY field_id,row_id');
				while ($v = $this->db->sql_fetchrow($result))
				{
					hash_update($hash,serialize($v));
					++$fields[(int) $v['field_id']]['entries'];
				}
				$this->db->sql_freeresult($result);
			}
		}
		return array('selected'=>$fields,'original'=>$original,'state'=>hash_final($hash));
	}

	public static function form($row, $type)
	{
		if (!$row) { return array('type'=>$type,'name'=>'','description'=>'','choices'=>'','pattern'=>'','position'=>'0'); }
		$d = field_data::definition($row);
		return array('type'=>$d['type'],'name'=>$d['title'],'description'=>$d['description'],'choices'=>implode("\n",$d['options']),
			'pattern'=>$d['pattern'],'position'=>(string) $row['position']);
	}

	private function changes($input, $row)
	{
		$type = (int) $input['type'];
		$old = $row ? self::form($row,$type) : array();
		$data = array();
		foreach (array('name','description',$type<2 ? 'pattern' : 'choices') as $key)
		{
			$value = $input[$key];
			if (!is_string($value) || strlen($value)>65535 || !preg_match('//u',$value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))
			{ throw new \DomainException('ACP_PLUS_DL_FIELD_INVALID'); }
			$input[$key] = str_replace(array("\r\n","\r"),"\n",$value);
		}
		$input['name'] = trim($input['name']);
		if ($input['name'] === '') { throw new \DomainException('ACP_PLUS_DL_FIELD_INVALID'); }
		foreach (array('name'=>'title','description'=>'description') as $key=>$column)
		{
			if ($row && $input[$key] === $old[$key]) { continue; }
			$data[$column] = utf8_encode_ucr(utf8_htmlspecialchars($input[$key]));
		}
		if ($row && $input['position'] !== $old['position'])
		{
			if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$input['position']) || $input['position']>2147483647)
			{ throw new \DomainException('ACP_PLUS_DL_FIELD_INVALID'); }
			$data['position'] = (int) $input['position'];
		}
		if ($type<2 && (!$row || $input['pattern'] !== $old['pattern']))
		{
			$pattern = $input['pattern'];
			if (strlen($pattern)>1020 || utf8_strlen($pattern)>255 ||
				($pattern !== '' && @preg_match('#(*LIMIT_MATCH=100000)' . $pattern . '#siU','') === false))
			{ throw new \DomainException('ACP_PLUS_DL_FIELD_PATTERN_INVALID'); }
			$source = $row && $row['source_json'] !== '' ? json_decode($row['source_json'],true) : array();
			if (!is_array($source)) { throw new \DomainException('ACP_PLUS_DL_FIELD_INVALID'); }
			// Leave the original regex and other converter provenance intact.
			$source['plus_field_rule'] = $pattern;
			$data['source_json'] = json_encode($source);
		}
		if ($type>=2 && (!$row || $input['choices'] !== $old['choices']))
		{
			if (trim($input['choices']) === '') { throw new \DomainException('ACP_PLUS_DL_FIELD_OPTIONS_INVALID'); }
			$choices = array_map('trim',explode("\n",trim($input['choices'])));
			$data['options_json'] = json_encode(array_map('utf8_htmlspecialchars',$choices));
			if (strlen($data['options_json'])>65535) { throw new \DomainException('ACP_PLUS_DL_FIELD_OPTIONS_INVALID'); }
		}
		return $data;
	}

	public function save($action, $ids, $type, $state, $form, $confirmed)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(true);
			if (!$this->access->field_admin(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$view = $this->review($action,$ids,$type);
			if (!hash_equals($view['state'],$state)) { throw new \DomainException('ACP_PLUS_DL_FIELD_CHANGED'); }
			$changed = true;
			if ($action === 'delete')
			{
				if (!$confirmed) { throw new \DomainException('ACP_PLUS_DL_FIELD_CONFIRM'); }
				$ids = array_keys($view['selected']);
				$where = $this->db->sql_in_set('field_id',$ids);
				$this->db->sql_query('UPDATE ' . $this->p . 'files SET review_revision=review_revision+1 WHERE file_id IN
					(SELECT file_id FROM ' . $this->p . "field_values WHERE $where)");
				$this->db->sql_query('DELETE FROM ' . $this->p . "field_values WHERE $where");
				$this->db->sql_query('DELETE FROM ' . $this->p . "fields WHERE $where");
				if ((int) $this->db->sql_affectedrows()!==count($ids)) { throw new \DomainException('ACP_PLUS_DL_FIELD_CHANGED'); }
			}
			else
			{
				$row = $view['original'];
				$form['type'] = $row ? (int) $row['field_type'] : $type;
				$data = $this->changes($form,$row);
				$changed = (bool) $data;
				if (!$row)
				{
					$data += array('field_type'=>$type,'position'=>0,'options_json'=>'[]','source_json'=>'{}');
					$this->db->sql_query('INSERT INTO ' . $this->p . 'fields ' . $this->db->sql_build_array('INSERT',$data));
					$id = (int) $this->db->sql_nextid();
					$this->db->sql_query('UPDATE ' . $this->p . 'fields SET position=' . min(2147483647,$id) . " WHERE field_id=$id");
					$ids = array($id);
				}
				elseif ($changed)
				{
					$this->db->sql_query('UPDATE ' . $this->p . 'fields SET ' . $this->db->sql_build_array('UPDATE',$data) .
						' WHERE field_id=' . (int) $row['field_id']);
				}
			}
			if ($changed)
			{ $this->log->add('admin',$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_DL_FIELD_' . strtoupper($action),false,array(implode(', ',$ids))); }
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download field update did not commit.'); }
		}
		catch (\Throwable $e)
		{
			if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
			throw $e;
		}
	}
}
