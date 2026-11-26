<?php
namespace phpbb2plus\arcade\service;

class batch_import
{
	public const PAGE = 10;
	private $db;
	private $access;
	private $sessions;
	private $editor;
	private $media;
	private $log;
	private $user;
	private $table;

	public function __construct($db,$access,$sessions,$editor,$media,$log,$user,$table)
	{
		$this->db=$db; $this->access=$access; $this->sessions=$sessions; $this->editor=$editor;
		$this->media=$media; $this->log=$log; $this->user=$user; $this->table=$table;
	}

	public function prepare($directory, $kind, $label)
	{
		$label = trim($label!=='' ? $label : basename(rtrim($directory,'/\\')));
		if ($label==='' || !preg_match('//u',$label) || utf8_strlen($label)>100 || preg_match('/[\x00-\x1f\x7f]/',$label))
		{ throw new \InvalidArgumentException('Choose an import label of 1 to 100 characters.'); }
		list($items,$skipped) = import_directory::scan($directory,$kind,$this->media);
		$key = hash('sha256',serialize(array($kind,$label,$items,$skipped)));
		$row = array('batch_key'=>$key,'label'=>utf8_encode_ucr(str_replace('&','&amp;',$label)),'kind'=>$kind,
			'settings_json'=>'','item_count'=>count($items),'scan_skipped'=>count($skipped),'prepared_at'=>time());
		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query("INSERT INTO {$this->table} " . $this->db->sql_build_array('INSERT',$row) . ' ON DUPLICATE KEY UPDATE batch_key=batch_key');
			$table = $this->table . '_items';
			$result = $this->db->sql_query_limit("SELECT ordinal FROM $table WHERE batch_key='$key'",1);
			$exists = (bool) $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!$exists)
			{
				foreach (array_chunk($items,100,true) as $chunk)
				{
					$rows = array();
					foreach ($chunk as $ordinal=>$item)
					{
						$rows[] = array('batch_key'=>$key,'ordinal'=>$ordinal,'stage_key'=>$item['key'],
							'source_name'=>utf8_encode_ucr(str_replace('&','&amp;',$item['source'])),
							'width'=>$item['width'],'height'=>$item['height']);
					}
					$this->db->sql_multi_insert($table,$rows);
				}
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		return array($key,count($items),count($skipped));
	}

	public function listing($offset)
	{
		$this->access->authorize('import_game');
		$result = $this->db->sql_query_limit("SELECT batch_key,label,kind,item_count,scan_skipped,position,added FROM {$this->table}
			ORDER BY prepared_at DESC,batch_key",26,$offset);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function batch($key, $lock=false)
	{
		$this->access->authorize('import_game');
		if (!preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new \DomainException('PLUS_ARC_IMPORT_MISSING'); }
		$result = $this->db->sql_query("SELECT * FROM {$this->table} WHERE batch_key='$key'" . ($lock ? ' FOR UPDATE' : ''));
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { throw new \DomainException('PLUS_ARC_IMPORT_MISSING'); }
		$start = (int) $row['position'];
		$result = $this->db->sql_query_limit("SELECT stage_key,source_name,width,height FROM {$this->table}_items
			WHERE batch_key='$key' AND ordinal>=$start ORDER BY ordinal",self::PAGE);
		$row['items'] = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if (count($row['items'])!==min(self::PAGE,(int) $row['item_count']-$start)) { throw new \RuntimeException('Incomplete Arcade import collection.'); }
		$row['settings'] = $row['settings_json']==='' ? array() : json_decode($row['settings_json'],true,512,JSON_THROW_ON_ERROR);
		return $row;
	}

	public function categories() { return $this->editor->category_options(false,true); }

	public function apply($key, $cursor, $input)
	{
		return $this->sessions->locked(function () use ($key,$cursor,$input) {
			$this->access->authorize('import_game',true);
			$row = $this->batch($key,true);
			$start = (int) $row['position'];
			if ($start!==$cursor || $start>=(int) $row['item_count']) { return false; }
			$options = $row['settings'];
			if (!$options) { $options = $this->editor->import_options($input['category'],$input['online'],$input['sizing']); }
			elseif ($options['category']!==-1 && !isset($this->categories()[$options['category']]))
			{
				// Only an explicit new selection can replace a vanished target.
				if ($input['category']==='') { throw new \DomainException('PLUS_ARC_EDIT_SCOPE_INVALID'); }
				$replacement = $this->editor->import_options($input['category'],(string) $options['online'],$options['sizing']);
				$options['category'] = $replacement['category'];
			}
			$added = 0;
			$portion = $row['items'];
			foreach ($portion as $item)
			{
				$this->db->sql_query('SAVEPOINT plus_arc_import_item');
				try { $this->editor->import_item($item,$row['kind'],$options); $added++; }
				catch (\DomainException $e)
				{
					if (!in_array($e->getMessage(),array('PLUS_ARC_ADD_IDENTITY','PLUS_ARC_ADD_CONFLICT','PLUS_ARC_ADD_UNAVAILABLE','PLUS_ARC_ADD_MEDIA_CHANGED'),true)) { throw $e; }
					$this->db->sql_query('ROLLBACK TO SAVEPOINT plus_arc_import_item');
				}
				$this->db->sql_query('RELEASE SAVEPOINT plus_arc_import_item');
			}
			$update = array('position'=>$start+count($portion),'added'=>(int) $row['added']+$added,'settings_json'=>json_encode($options,JSON_THROW_ON_ERROR));
			$this->db->sql_query("UPDATE {$this->table} SET " . $this->db->sql_build_array('UPDATE',$update) . " WHERE batch_key='$key'");
			$this->log->add('admin',(int) $this->user->data['user_id'],$this->user->ip,'LOG_PLUS_ARC_IMPORT',false,array($row['label'],$added,count($portion)-$added));
			return true;
		});
	}
}
