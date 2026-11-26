<?php
namespace phpbb2plus\arcade\service;

/** Editable catalogue metadata. File storage, game identities and score records have separate owners. */
class game_editor
{
	public const LIMITS = array('win_width'=>'4096','win_height'=>'4096','game_charge'=>'4294967295',
		'game_reward'=>'4294967295','game_bonus'=>'65535','at_game_bonus'=>'32767',
		'highscore_limit'=>'9223372036854775807','at_highscore_limit'=>'32767');
	public const CHOICES = array('game_avail'=>1,'allow_guest'=>1,'game_show_score'=>1,'reverse_list'=>1,
		'game_autosize'=>1,'game_control'=>3,'score_type'=>8,'level_required'=>2);
	public const TEXT = array('game_desc','instructions');
	public const SORT = array('game_id'=>'g.game_id','allow_guest'=>'g.allow_guest','date_added'=>'g.date_added',
		'alphabetical'=>'g.game_desc','cat_id'=>'g.cat_id','offline'=>'g.game_avail','game_played'=>'g.played');
	private $db;
	private $access;
	private $sessions;
	private $catalog;
	private $categories;
	private $log;
	private $user;
	private $prefix;
	private $media;
	private $identity;

	public function __construct($db, $access, $sessions, $catalog, $categories, $log, $user, $prefix, $media)
	{
		$this->db = $db;
		$this->access = $access;
		$this->sessions = $sessions;
		$this->catalog = $catalog;
		$this->categories = $categories;
		$this->log = $log;
		$this->user = $user;
		$this->prefix = $prefix;
		$this->media = $media;
		$this->identity = new game_identity($db,$prefix);
	}

	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function listing($offset, $term, $category, $sort, $order)
	{
		$this->access->authorize('games');
		$table = $this->prefix . 'games';
		$where = 'g.game_id>0';
		if ($category>0) { $where .= ' AND g.cat_id=' . (int) $category; }
		elseif ($category===-1) { $where .= ' AND (g.cat_id IS NULL OR g.cat_id<=0)'; }
		if ($term !== '')
		{
			$title = $this->catalog->title_filter($term,0);
			$any = $this->db->get_any_char();
			$name = $this->db->sql_like_expression($any . str_replace('\\','\\\\',utf8_strtolower($term)) . $any);
			$where .= " AND (LOWER(g.game_name) $name OR (1=1$title))";
		}
		$total = (int) $this->rows("SELECT COUNT(*) AS total FROM $table g WHERE $where")[0]['total'];
		$size = (int) $this->catalog->setting('games_per_admin_page',20);
		$size = $size > 0 ? min(1000,$size) : max(1,$total);
		$start = intdiv(min(max(0,$offset),max(0,$total-1)),$size)*$size;
		$direction = $order==='ASC' ? 'ASC' : 'DESC';
		$column = self::SORT[$sort] ?? self::SORT['game_id'];
		$sorting = $sort==='offline' ? "g.game_avail,g.game_desc $direction" : "$column $direction";
		$result = $this->db->sql_query_limit("SELECT g.game_id,g.game_name,g.game_desc,g.game_avail,c.cat_name
			FROM $table g LEFT JOIN {$this->prefix}categories c ON c.cat_id=g.cat_id WHERE $where ORDER BY $sorting,g.game_id $direction",$size,$start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return array($rows,$total,$size,$start);
	}

	public function find($id, $lock = false)
	{
		$this->access->authorize('games');
		$row = $id > 0 ? ($this->rows('SELECT * FROM ' . $this->prefix . 'games WHERE game_id=' . (int) $id . ($lock ? ' FOR UPDATE' : ''))[0] ?? null) : null;
		if (!$row) { throw new \DomainException('PLUS_ARC_EDIT_MISSING'); }
		return $row;
	}

	public static function revision($row)
	{
		// A new play or score while the form is open does not change its metadata.
		$fields = array_merge(self::TEXT,array_keys(self::LIMITS),array_keys(self::CHOICES),
			array('game_id','game_name','cat_id','group_required','native_group_id','rank_required'));
		$values = array();
		foreach ($fields as $key) { $values[$key] = $row[$key]; }
		return hash('sha256',serialize($values));
	}

	private static function number($value, $max)
	{
		if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',$value) || strlen($value)>strlen($max) ||
			(strlen($value)===strlen($max) && strcmp($value,$max)>0)) { throw new \DomainException('PLUS_ARC_EDIT_INVALID'); }
		return $value;
	}

	private function valid_category($id, $nodes)
	{
		if ($id === -1) { return true; }
		if ($id < 1 || !isset($nodes[$id])) { return false; }
		$row = $nodes[$id];
		$parent = (int) $row['cat_parent'];
		if ($row['cat_type'] === 'p') { return $parent === 0; }
		return $row['cat_type'] === 's' && $parent>0 && isset($nodes[$parent]) &&
			$nodes[$parent]['cat_type'] === 'p' && (int) $nodes[$parent]['cat_parent'] === 0;
	}

	public function category_options($adding = false, $importing = false)
	{
		$this->access->authorize($importing ? 'import_game' : ($adding ? 'add_game' : 'games'));
		$nodes = $this->categories->records();
		$categories = array();
		foreach ($nodes as $id=>$row)
		{
			if ($id>0 && $this->valid_category($id,$nodes))
			{
				$parent = (int) $row['cat_parent'];
				$categories[$id] = ($parent ? catalog::plain($nodes[$parent]['cat_name']) . ' / ' : '') . catalog::plain($row['cat_name']);
			}
		}
		return $categories;
	}

	public function options($game, $adding = false)
	{
		$options = array('cat_id'=>$this->category_options($adding),'group_required'=>array(),'rank_required'=>array());
		foreach ($this->categories->groups() as $row) { $options['group_required'][$row['group_id']] = catalog::plain($row['group_name']); }
		foreach ($this->rows('SELECT rank_id,rank_title FROM ' . RANKS_TABLE . ' WHERE rank_special=0 ORDER BY rank_min,rank_id') as $row)
		{ $options['rank_required'][$row['rank_id']] = catalog::plain($row['rank_title']); }
		$group = (int) $game['native_group_id'];
		$legacy = (int) $game['group_required'];
		$current = array('cat_id'=>$options['cat_id'][$game['cat_id']] ?? '',
			'group_required'=>$group ? ($options['group_required'][$group] ?? '') : ($legacy ? $this->categories->group_label($legacy) : ''),
			'rank_required'=>$options['rank_required'][$game['rank_required']] ?? '');
		return array($options,$current);
	}

	private function scoped_values($input, $nodes)
	{
		$values = array();
		if ($input['cat_id'] !== 'keep')
		{
			$id = $input['cat_id'] === '-1' ? -1 : (int) self::number($input['cat_id'],'2147483647');
			if (!$this->valid_category($id,$nodes)) { throw new \DomainException('PLUS_ARC_EDIT_SCOPE_INVALID'); }
			$values['cat_id'] = $id;
		}
		if ($input['rank_required'] !== 'keep')
		{
			$id = (int) self::number($input['rank_required'],'16777215');
			if ($id && !$this->rows('SELECT rank_id FROM ' . RANKS_TABLE . " WHERE rank_id=$id AND rank_special=0 LOCK IN SHARE MODE"))
			{ throw new \DomainException('PLUS_ARC_EDIT_SCOPE_INVALID'); }
			$values['rank_required'] = $id;
		}
		if ($input['group_required'] !== 'keep')
		{
			$id = (int) self::number($input['group_required'],'16777215');
			if ($id && !$this->rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_id=$id AND group_type<>" . GROUP_SPECIAL . ' LOCK IN SHARE MODE'))
			{ throw new \DomainException('PLUS_ARC_EDIT_SCOPE_INVALID'); }
			$values['native_group_id'] = $id;
			$values['group_required'] = $id ? -1 : 0;
		}
		return $values;
	}

	private function metadata($input, $row)
	{
		$values = array();
		foreach (self::TEXT as $field)
		{
			$text = $input[$field];
			$original = str_replace(array("\r\n","\r"),"\n",catalog::plain($row[$field]));
			$text = str_replace(array("\r\n","\r"),"\n",$text);
			if ($text === $original) { continue; }
			if (!preg_match('//u',$text) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text) ||
				($field === 'game_desc' && (utf8_strlen($text)>255 || strpbrk($text,"\n\t")!==false)) ||
				($field === 'instructions' && strlen($text)>65535)) { throw new \DomainException('PLUS_ARC_EDIT_INVALID'); }
			$values[$field] = utf8_encode_ucr(str_replace('&','&amp;',$text));
		}
		foreach (self::LIMITS + self::CHOICES as $field=>$limit)
		{
			$value = $input[$field];
			if ($value === self::display_number($row[$field])) { continue; } // Keep historical NULLs and out-of-range values verbatim.
			$values[$field] = self::number($value,(string) $limit);
		}
		return $values;
	}

	public static function display_number($stored)
	{
		return $stored === null || $stored === '' ? '0' : (string) $stored;
	}

	private function category_changes($nodes, $before, $after)
	{
		// Imported lifetime totals can predate the remaining catalogue. Transfer
		// only this game's contribution, as the source editor did.
		$counts = array();
		foreach (array($before,$after) as $index=>$game)
		{
			$cat = (int) $game['cat_id'];
			$direct = $cat>0 && isset($nodes[$cat]) && $nodes[$cat]['cat_type']!=='l' ? $cat : 0;
			$parent = $direct ? (int) $nodes[$direct]['cat_parent'] : 0;
			$targets = array_unique(array_filter(array(-1,$direct,$parent)));
			foreach ($targets as $target)
			{
				if (!isset($nodes[$target])) { continue; }
				if (!isset($counts[$target])) { $counts[$target] = array('games'=>0,'plays'=>'0'); }
				if (($target===-1 || $target===$direct) && (int) $game['game_avail']===1) { $counts[$target]['games'] += $index ? 1 : -1; }
				$plays = (string) (int) $game['played'];
				$counts[$target]['plays'] = currency::add($counts[$target]['plays'],($index ? '' : '-') . $plays);
			}
		}
		foreach ($counts as $target=>$delta)
		{
			$total = max(0,(int) $nodes[$target]['total_games'] + $delta['games']);
			$before_played = (string) $nodes[$target]['total_played'];
			self::number($before_played,'18446744073709551615');
			$plays = currency::add($before_played,$delta['plays']);
			if ($plays[0]==='-') { $plays = '0'; }
			self::number($plays,'18446744073709551615');
			if ($total>4294967295) { throw new \DomainException('PLUS_ARC_EDIT_COUNT'); }
			$counts[$target] = array('total_games'=>$total,'total_played'=>$plays);
		}
		return $counts;
	}

	public function preparations($game = null)
	{
		$this->access->authorize($game===null ? 'add_game' : 'games');
		return $game!==null && ($game['asset_status']==='ready' || $game['main_asset']!=='') ? array() : $this->media->available($game);
	}

	public function defaults($lock = false)
	{
		$this->access->authorize('add_game');
		$id = max(0,(int) $this->catalog->setting('games_default_id',0));
		$row = $this->rows("SELECT * FROM {$this->prefix}games WHERE game_id=$id" . ($lock ? ' LOCK IN SHARE MODE' : ''))[0] ?? array();
		$numbers = array_fill_keys(array_keys(self::LIMITS+self::CHOICES),0);
		$numbers += array('cat_id'=>-1,'group_required'=>0,'native_group_id'=>0,'rank_required'=>0);
		$defaults = array_intersect_key($row,$numbers) + $numbers;
		if (!$id) { $defaults['game_show_score'] = 1; }
		$defaults['score_type'] = 0;
		if ((int) $defaults['cat_id']<=0) { $defaults['cat_id'] = -1; }
		return $defaults + array('game_id'=>0,'game_name'=>'','game_desc'=>'','instructions'=>'');
	}

	private function unused_identity($value, $tables)
	{
		$literal = is_int($value) ? (string) $value : "'" . $this->db->sql_escape($value) . "'";
		$lookups = array();
		foreach ($tables as $table=>$columns)
		{
			$conditions = array();
			foreach ((array) $columns as $column) { $conditions[] = $column . '=' . $literal; }
			$lookups[] = "EXISTS (SELECT 1 FROM {$this->prefix}$table WHERE " . implode(' OR ',$conditions) . ')';
		}
		$result = $this->db->sql_query('SELECT (' . implode(' OR ',$lookups) . ') AS found');
		$used = (bool) $this->db->sql_fetchfield('found');
		$this->db->sql_freeresult($result);
		if ($used) { throw new \DomainException('PLUS_ARC_ADD_IDENTITY'); }
	}

	public function create($key, $revision, $input)
	{
		return $this->sessions->locked(function () use ($key,$revision,$input) {
			$this->access->authorize('add_game',true);
			$stage = $this->media->locked($key);
			$old = $this->defaults(true);
			if (!hash_equals(self::revision($old),$revision)) { throw new \DomainException('PLUS_ARC_EDIT_STALE'); }
			$nodes = $this->categories->records(true);
			$values = array_replace($old,$this->metadata($input,$old),$this->scoped_values($input,$nodes));
			if (!$this->valid_category((int) $values['cat_id'],$nodes)) { throw new \DomainException('PLUS_ARC_EDIT_SCOPE_INVALID'); }
			$name = $stage['game_name'];
			$values['game_flash'] = strtolower(pathinfo($stage['main_file'],PATHINFO_EXTENSION))==='swf' ? 1 : 0;
			$id = $this->insert($stage,$name,$values,$nodes);
			$this->log->add('admin',(int) $this->user->data['user_id'],$this->user->ip,'LOG_PLUS_ARC_ADD_GAME',false,array($id));
			return $id;
		});
	}

	private function insert($stage, $name, $values, $nodes)
	{
		// A detached historical result still owns its protocol name even when
		// the old catalogue row is gone. Creation must not adopt that history.
		$this->identity->available($name);
		unset($values['game_id']);
		$values['game_name'] = $name;
		$values += array('game_path'=>'','image_path'=>'','main_asset'=>'','asset_status'=>'missing','game_use_gl'=>0,'date_added'=>time());
		$this->db->sql_query("INSERT INTO {$this->prefix}games " . $this->db->sql_build_array('INSERT',$values));
		$id = (int) $this->db->sql_nextid();
		if ($id<=0 || $id>8388607 || $id===(int) $this->catalog->setting('games_default_id',0))
		{ throw new \DomainException('PLUS_ARC_ADD_IDENTITY'); }
		$this->unused_identity($id,array('favorites'=>'fav_game_id','saves'=>'game_id','launches'=>'game_id','assets'=>'game_id','staged'=>'game_id'));
		$this->media->bind($stage,$values+array('game_id'=>$id));
		if ((int) $values['game_avail']===1)
		{
			foreach (array_unique(array(-1,(int) $values['cat_id'])) as $target)
			{
				if (!isset($nodes[$target]) || (int) $nodes[$target]['total_games']>=4294967295) { throw new \DomainException('PLUS_ARC_EDIT_COUNT'); }
				$this->db->sql_query("UPDATE {$this->prefix}categories SET total_games=total_games+1 WHERE cat_id=$target");
			}
		}
		return $id;
	}

	public function import_options($category, $online, $sizing)
	{
		$this->access->authorize('import_game');
		if ((!preg_match('/^[1-9][0-9]{0,6}$/D',$category) && $category!=='-1') ||
			!in_array($online,array('0','1'),true) || !in_array($sizing,array('detect','default'),true))
		{ throw new \DomainException('FORM_INVALID'); }
		if (!$this->valid_category((int) $category,$this->categories->records(true))) { throw new \DomainException('PLUS_ARC_EDIT_SCOPE_INVALID'); }
		$default = (int) $this->catalog->setting('games_default_id',0);
		$row = $this->rows("SELECT win_width,win_height FROM {$this->prefix}games WHERE game_id=$default LOCK IN SHARE MODE")[0] ?? array();
		return array('category'=>(int) $category,'online'=>(int) $online,'sizing'=>$sizing,
			'width'=>min(4096,max(1,(int) ($row['win_width'] ?? 0) ?: 550)),
			'height'=>min(4096,max(1,(int) ($row['win_height'] ?? 0) ?: 450)));
	}

	/** batch_import has locked the batch and the Arcade coordinator. */
	public function import_item($item, $kind, $options)
	{
		$this->access->authorize('import_game');
		$stage = $this->media->locked($item['stage_key']);
		$nodes = $this->categories->records(true);
		if (!$this->valid_category($options['category'],$nodes)) { throw new \DomainException('PLUS_ARC_EDIT_SCOPE_INVALID'); }
		$file = $stage['main_file'];
		$package = $kind==='package';
		$name = $package ? $stage['game_name'] : $file;
		if (strlen($name)>50) { throw new \DomainException('PLUS_ARC_ADD_IDENTITY'); }
		foreach (array_unique(array($file,explode('.',$file,2)[0])) as $other)
		{ if ($other!==$name) { $this->identity->available($other,0,false); } }
		$title = ucfirst(trim(str_replace('_',' ',$name)));
		$detect = $options['sizing']==='detect';
		$values = array('game_desc'=>utf8_encode_ucr(str_replace('&','&amp;',$title)),'game_avail'=>$options['online'],
			'cat_id'=>$options['category'],'reverse_list'=>0,'game_flash'=>$package ? 1 : 0,
			'win_width'=>$detect ? ((int) $item['width'] ?: 550) : $options['width'],
			'win_height'=>$detect ? ((int) $item['height'] ?: 450) : $options['height']);
		return $this->insert($stage,$name,$values,$nodes);
	}

	public function save($id, $revision, $input, $key = '')
	{
		$this->sessions->locked(function () use ($id,$revision,$input,$key) {
			$this->access->authorize('games',true);
			$row = $this->find($id,true);
			if (!hash_equals(self::revision($row),$revision)) { throw new \DomainException('PLUS_ARC_EDIT_STALE'); }
			if ($key!=='')
			{
				if (!$this->catalog->unique_name($row)) { throw new \DomainException('PLUS_ARC_ADD_CONFLICT'); }
				$this->media->bind($this->media->locked($key),$row);
				$this->log->add('admin',(int) $this->user->data['user_id'],$this->user->ip,'LOG_PLUS_ARC_ATTACH_GAME',false,array((int) $id));
			}
			$changes = $this->metadata($input,$row);
			$nodes = $input['cat_id'] !== 'keep' || isset($changes['game_avail']) ? $this->categories->records(true) : array();
			$changes += $this->scoped_values($input,$nodes);
			$changes = array_filter($changes,static function ($value,$key) use ($row) {
				return $row[$key] === null || (string) $value !== (string) $row[$key];
			},ARRAY_FILTER_USE_BOTH);
			if (!$changes) { return; }
			if (isset($changes['reverse_list']) && !$this->catalog->unique_name($row)) { throw new \DomainException('PLUS_ARC_EDIT_AMBIGUOUS'); }
			$moved = isset($changes['cat_id']);
			$game = array_merge($row,$changes);
			$history = $moved ? new category_history($this->db,$this->prefix) : null;
			$table = $this->prefix . 'games';
			$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$changes) . ' WHERE game_id=' . (int) $id);
			if ($moved || isset($changes['game_avail']))
			{
				$counts = $this->category_changes($nodes,$row,$game);
				if ($moved) { $history->recalculate($nodes,$nodes,$counts,(int) $id); }
				else
				{
					foreach ($counts as $target=>$values)
					{
						if ((int) $nodes[$target]['total_games']!==$values['total_games'])
						{
							$this->db->sql_query("UPDATE {$this->prefix}categories SET total_games=" . $values['total_games'] . " WHERE cat_id=$target");
						}
					}
				}
			}
			if (isset($changes['reverse_list']))
			{
				score::refresh_winner($this->db,$this->prefix,$game,false);
				score::refresh_winner($this->db,$this->prefix,$game,true);
			}
			$this->log->add('admin',(int) $this->user->data['user_id'],$this->user->ip,'LOG_PLUS_ARC_EDIT_GAME',false,array((int) $id));
		});
	}

	public function rename($id,$revision,$name)
	{
		$this->sessions->locked(function () use ($id,$revision,$name) {
			$this->access->authorize('games',true);
			$game=$this->find($id,true);
			if (!hash_equals(self::revision($game),$revision)) { throw new \DomainException('PLUS_ARC_EDIT_STALE'); }
			if ($this->identity->rename($game,$name))
			{
				$this->log->add('admin',(int)$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_ARC_RENAME_GAME',false,
					array((int)$id,utf8_htmlspecialchars($game['game_name']),utf8_htmlspecialchars($name)));
			}
		});
	}
}
