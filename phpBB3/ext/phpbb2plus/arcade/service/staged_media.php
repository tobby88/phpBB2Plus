<?php
namespace phpbb2plus\arcade\service;

/** CLI-supplied immutable files. A browser can claim an available manifest, never select a server path. */
class staged_media
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

	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$data = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $data;
	}

	private static function relative($name)
	{
		return is_string($name) && $name!=='' && strlen($name)<=255 && preg_match('//u',$name) &&
			!preg_match('~[\\\\:?#%<>|"*\x00-\x1f\x7f\xf0-\xf4]|(?:^|/)\.|(?:^/|/$|//)~',$name);
	}

	private function directory()
	{
		$root = realpath($this->root);
		$relative = trim($this->config['upload_path'],'/\\');
		if (!$root || !self::relative($relative)) { throw new \RuntimeException('Invalid phpBB file storage.'); }
		$path = realpath($root . '/' . $relative);
		if (!$path || !is_dir($path) || strpos($path,$root . DIRECTORY_SEPARATOR)!==0 || !$this->config['plus_arc_media_key'])
		{ throw new \RuntimeException('Arcade file storage is unavailable.'); }
		return $path;
	}

	private function source($root, $name)
	{
		if (!self::relative($name)) { throw new \InvalidArgumentException('Choose a relative media filename.'); }
		$current = $root;
		foreach (explode('/',$name) as $segment)
		{
			$current .= DIRECTORY_SEPARATOR . $segment;
			if (is_link($current)) { throw new \InvalidArgumentException('Choose ordinary local files without symbolic links.'); }
		}
		$path = realpath($current);
		if (!$path || !is_file($path) || strpos($path,$root . DIRECTORY_SEPARATOR)!==0)
		{ throw new \InvalidArgumentException('A selected local file is missing from the game directory.'); }
		return $path;
	}

	private function filename($key, $name)
	{
		return 'arcade_' . hash_hmac('sha256',"staged\0" . $key . "\0" . $name,$this->config['plus_arc_media_key']);
	}

	private function record($key, $lock = false)
	{
		if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new \DomainException('PLUS_ARC_ADD_UNAVAILABLE'); }
		return $this->rows("SELECT * FROM {$this->prefix}staged WHERE stage_key='$key'" . ($lock ? ' FOR UPDATE' : ''))[0] ?? null;
	}

	private function verified($path, $file)
	{
		clearstatcache(true,$path);
		return !is_link($path) && is_file($path) && (string) filesize($path)===(string) $file['bytes'] &&
			hash_file('sha256',$path)===$file['digest'];
	}

	private function copy($source, $path, $file)
	{
		if ($this->verified($path,$file)) { return; }
		// The manifest row exclusively owns this scratch name. A process killed
		// mid-copy cannot leave a playable file behind, and the same command can resume.
		$scratch = dirname($path) . '/stage_' . basename($path);
		if (is_link($path) || file_exists($path) || is_link($scratch))
		{ throw new \RuntimeException('A different file already occupies the private staging name.'); }
		if (is_file($scratch) && !unlink($scratch)) { throw new \RuntimeException('Cannot resume the partial local media copy.'); }
		$out = @fopen($scratch,'xb');
		if (!$out) { throw new \RuntimeException('Cannot write the private staging copy.'); }
		try
		{
			$in = fopen($source,'rb');
			if (!$in) { throw new \RuntimeException('The source file is no longer readable.'); }
			try { $bytes = stream_copy_to_stream($in,$out); }
			finally { fclose($in); }
			fclose($out);
			if ($bytes!==$file['bytes'] || !$this->verified($scratch,$file)) { throw new \RuntimeException('The source file changed while staging.'); }
			clearstatcache(true,$path);
			if (file_exists($path) || is_link($path) || !rename($scratch,$path))
			{ throw new \RuntimeException('Cannot publish the prepared file without replacing another file.'); }
		}
		finally
		{
			if (is_resource($out)) { fclose($out); }
			if (!is_link($scratch) && is_file($scratch)) { unlink($scratch); }
		}
	}

	public function prepare($main, $extra, $icon)
	{
		if (PHP_SAPI!=='cli' || strpos($main,'://')!==false || is_link($main)) { throw new \InvalidArgumentException('Choose a local main file.'); }
		$root = realpath(dirname($main));
		$entry = basename($main);
		if (!$root || !$entry || !self::relative($entry)) { throw new \InvalidArgumentException('Invalid game entry file.'); }
		$extension = strtolower(pathinfo($entry,PATHINFO_EXTENSION));
		$stem = pathinfo($entry,PATHINFO_FILENAME);
		$name = $extension==='swf' && media_format::entry_name($stem,true)===$entry ? $stem : $entry;
		if ($name==='' || strlen($name)>50 || preg_match('/\s/u',$name)) { throw new \InvalidArgumentException('Choose a game identifier of up to 50 bytes without spaces.'); }
		$files = $paths = $seen = array();
		$names = array_merge(array($entry),$extra,$icon!=='' ? array($icon) : array());
		foreach (array_unique($names) as $relative)
		{
			$source = $this->source($root,$relative);
			$case = utf8_strtolower($relative);
			if (isset($seen[$case])) { throw new \InvalidArgumentException('The selected media names differ only in case.'); }
			$seen[$case] = true;
			$mime = media_format::type($source,strtolower(pathinfo($relative,PATHINFO_EXTENSION)),$relative!==$entry);
			if (!$mime || ($relative===$icon && strpos($mime,'image/')!==0)) { throw new \InvalidArgumentException('The selected file is not a supported game medium.'); }
			$digest = hash_file('sha256',$source);
			$bytes = filesize($source);
			if ($digest===false || $bytes===false) { throw new \RuntimeException('The selected medium cannot be read.'); }
			$files[$relative] = array('mime'=>$mime,'bytes'=>$bytes,'digest'=>$digest,'checksum'=>$mime==='application/x-shockwave-flash' ? md5_file($source) : '');
			$paths[$relative] = $source;
		}
		ksort($files,SORT_STRING);
		$json = json_encode($files,JSON_THROW_ON_ERROR);
		$key = hash('sha256',$entry . "\0" . $icon . "\0" . $json);
		$target = $this->directory();
		$table = $this->prefix . 'staged';
		// A durable manifest precedes any copies. The same command resumes a
		// partial preparation; its row lock serializes retry, discard and claim.
		$row = array('stage_key'=>$key,'game_name'=>$name,'main_file'=>$entry,'icon_file'=>$icon,'manifest_json'=>$json,'prepared_at'=>time());
		$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',$row) . ' ON DUPLICATE KEY UPDATE stage_key=stage_key');
		$this->db->sql_transaction('begin');
		try
		{
			$stage = $this->record($key,true);
			if (!$stage || $stage['manifest_json']!==$json) { throw new \RuntimeException('The preparation changed. Run the command again.'); }
			if (!$stage['game_id'])
			{
				foreach ($files as $relative=>$file) { $this->copy($paths[$relative],$target . '/' . $this->filename($key,$relative),$file); }
				$this->db->sql_query("UPDATE $table SET ready=1 WHERE stage_key='$key'");
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		return array($key,(int) $stage['game_id']);
	}

	public function discard($key)
	{
		if (PHP_SAPI!=='cli') { throw new \LogicException('Local command required.'); }
		$target = $this->directory();
		// Commit the unavailability before unlinking. An interrupted discard
		// therefore remains recoverable without ever offering missing files.
		$this->db->sql_transaction('begin');
		try
		{
			$stage = $this->record($key,true);
			if (!$stage || $stage['game_id']) { throw new \RuntimeException('Only an unassigned preparation can be discarded.'); }
			$this->db->sql_query("UPDATE {$this->prefix}staged SET ready=0 WHERE stage_key='$key'");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		$this->db->sql_transaction('begin');
		try
		{
			$stage = $this->record($key,true);
			if (!$stage || $stage['game_id'] || $stage['ready']) { throw new \RuntimeException('The preparation changed before it could be discarded.'); }
			$delete = array();
			foreach (json_decode($stage['manifest_json'],true,512,JSON_THROW_ON_ERROR) as $name=>$file)
			{
				$storage = $this->filename($key,$name);
				$path = $target . '/' . $storage;
				$scratch = $target . '/stage_' . $storage;
				if (is_link($scratch)) { throw new \RuntimeException('Unexpected link in private staging storage.'); }
				if (is_file($scratch)) { $delete[] = $scratch; }
				if (!file_exists($path) && !is_link($path)) { continue; }
				if (!$this->verified($path,$file) || $this->rows("SELECT game_id FROM {$this->prefix}assets WHERE storage_name='$storage' LIMIT 1") ||
					$this->rows("SELECT game_id FROM {$this->prefix}games WHERE icon_storage='$storage' LIMIT 1") ||
					$this->rows("SELECT cat_id FROM {$this->prefix}categories WHERE icon_storage='$storage' LIMIT 1"))
				{ throw new \RuntimeException('This file is already in use or differs from the preparation.'); }
				$delete[] = $path;
			}
			foreach ($delete as $path) { if (!unlink($path)) { throw new \RuntimeException('A prepared copy could not be removed.'); } }
			$this->db->sql_query("DELETE FROM {$this->prefix}staged WHERE stage_key='$key'");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	public function available($game = null)
	{
		$where = 'ready=1 AND game_id=0';
		if ($game!==null)
		{
			$file = $this->db->sql_escape(media_format::entry_name($game['game_name'],$game['game_flash']));
			$where .= " AND BINARY main_file='$file'";
		}
		return $this->rows("SELECT stage_key,game_name,main_file,prepared_at FROM {$this->prefix}staged WHERE $where ORDER BY game_name,prepared_at,stage_key");
	}

	public function locked($key)
	{
		if (!$this->db->get_transaction()) { throw new \LogicException('Arcade coordinator transaction required.'); }
		$stage = $this->record($key,true);
		if (!$stage || !$stage['ready'] || $stage['game_id']) { throw new \DomainException('PLUS_ARC_ADD_UNAVAILABLE'); }
		return $stage;
	}

	/** Caller holds the coordinator, stage and game locks and has rechecked its own ACP permission. */
	public function bind($stage, $game)
	{
		$id = (int) $game['game_id'];
		$key = $stage['stage_key'];
		if (!$this->db->get_transaction() || $id<1) { throw new \LogicException('Arcade coordinator transaction required.'); }
		if (media_format::entry_name($game['game_name'],$game['game_flash'])!==$stage['main_file'] || $game['asset_status']==='ready' ||
			$game['main_asset']!=='' || $this->rows("SELECT game_id FROM {$this->prefix}assets WHERE game_id=$id LIMIT 1 FOR UPDATE"))
		{ throw new \DomainException('PLUS_ARC_ADD_CONFLICT'); }
		$files = json_decode($stage['manifest_json'],true,512,JSON_THROW_ON_ERROR);
		$target = $this->directory();
		$values = array('asset_status'=>'ready','main_asset'=>$stage['main_file']);
		foreach ($files as $name=>$file)
		{
			$storage = $this->filename($key,$name);
			if (!$this->verified($target . '/' . $storage,$file)) { throw new \DomainException('PLUS_ARC_ADD_MEDIA_CHANGED'); }
			$asset = array('game_id'=>$id,'path_key'=>hash('sha256',$name),'relative_name'=>$name,'storage_name'=>$storage,
				'mime_type'=>$file['mime'],'bytes'=>$file['bytes'],'legacy_checksum'=>$file['checksum']);
			$this->db->sql_query("INSERT INTO {$this->prefix}assets " . $this->db->sql_build_array('INSERT',$asset));
			if ($name===$stage['icon_file'] && empty($game['icon_storage']))
			{ $values += array('icon_storage'=>$storage,'icon_mime'=>$file['mime'],'icon_bytes'=>$file['bytes']); }
		}
		$this->db->sql_query("UPDATE {$this->prefix}games SET " . $this->db->sql_build_array('UPDATE',$values) . " WHERE game_id=$id");
		$this->db->sql_query("UPDATE {$this->prefix}staged SET game_id=$id WHERE stage_key='$key' AND ready=1 AND game_id=0");
		if ((int) $this->db->sql_affectedrows()!==1) { throw new \DomainException('PLUS_ARC_ADD_UNAVAILABLE'); }
	}
}
