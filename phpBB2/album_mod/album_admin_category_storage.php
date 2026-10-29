<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_lifecycle_authority.php';

// Public ACP hierarchy writes share the same lease as uploads, category
// removal and moderation. No cached tree, caller transaction or private gallery
// is used as the source of a public category mutation.
class AlbumAdminCategoryStorage
{
	private $database;
	private $prefix;
	function __construct($database, $prefix) { $this->database = $database; $this->prefix = $prefix; }
	private function rows($storage, $sql)
	{
		$r = $storage->sql_query($sql);
		try { $rows = $storage->sql_fetchrowset($r); } finally { $storage->sql_freeresult($r); }
		if (!is_array($rows)) { throw new AlbumFileCoordinatorFailure('Public category rows unavailable'); }
		foreach ($rows as &$row) { foreach (array_keys($row) as $key) { if (is_int($key)) { unset($row[$key]); } } } unset($row);
		return $rows;
	}
	private static function saved($previous,$value) { return is_int($previous) ? (int)$value : (string)$value; }
	private static function id($value, $root = false)
	{
		if (!is_int($value) || $value < ($root ? 0 : 1) || $value > 16777215) { throw new AlbumFileCoordinatorFailure('Invalid public category identifier'); }
		return $value;
	}
	private static function fields($values)
	{
		if (!is_array($values)) { throw new AlbumFileCoordinatorFailure('Invalid public category values'); }
		foreach (array('cat_title'=>255, 'cat_desc'=>65535) as $name=>$limit)
		{
			if (!isset($values[$name]) || !is_string($values[$name]) || strlen($values[$name]) > $limit * 4 || preg_match('//u', $values[$name]) !== 1 || preg_match_all('/./us', $values[$name]) > $limit) { throw new AlbumFileCoordinatorFailure('Invalid public category text'); }
		}
		if (trim($values['cat_title']) === '') { throw new AlbumFileCoordinatorFailure('Empty public category title'); }
		foreach (array('view','upload','rate','comment','edit','delete') as $level)
		{
			$name = 'cat_' . $level . '_level'; $allowed = array(ALBUM_USER,ALBUM_PRIVATE,ALBUM_MOD,ALBUM_ADMIN);
			if (!in_array($level, array('edit','delete'), true)) { $allowed[] = ALBUM_GUEST; }
			if (!isset($values[$name]) || !is_int($values[$name]) || !in_array($values[$name], $allowed, true)) { throw new AlbumFileCoordinatorFailure('Invalid public category permission'); }
		}
		if (!isset($values['cat_approval']) || !is_int($values['cat_approval']) || !in_array($values['cat_approval'],array(ALBUM_USER,ALBUM_MOD,ALBUM_ADMIN),true)) { throw new AlbumFileCoordinatorFailure('Invalid public category approval'); }
		return $values;
	}
	private static function ancestry($rows, $parent, $excluded)
	{
		$seen = array();
		while ($parent !== 0)
		{
			if ($parent === $excluded || isset($seen[$parent]) || !isset($rows[$parent])) { throw new AlbumFileCoordinatorFailure('Invalid public category ancestry'); }
			$seen[$parent] = true; $parent = (int) $rows[$parent]['cat_parent'];
		}
	}
	function change($snapshot, $action, $category = null, $parent = 0, $values = array(), $move = 0)
	{
		if (!in_array($action,array('new','edit','order'),true)) { throw new AlbumFileCoordinatorFailure('Invalid public category action'); }
		if ($action === 'new') { if ($category !== null) { throw new AlbumFileCoordinatorFailure('New category already has an identity'); } }
		else { self::id($category); }
		if ($action !== 'order') { self::id($parent,true); $values = self::fields($values); }
		elseif (!is_int($move) || !in_array($move,array(-15,15),true)) { throw new AlbumFileCoordinatorFailure('Invalid public category move'); }
		$storage = null;
		try
		{
			$storage = new AlbumFileCoordinator($this->database,$this->prefix); $storage->begin();
			$authority = new AlbumLifecycleAuthority($storage,$this->prefix);
			$context = $action === 'new' ? $authority->lock_category_creation_admin($snapshot) : $authority->lock_admin($snapshot,$category);
			if ($context === false) { throw new AlbumFileCoordinatorFailure('Current public category administration denied'); }
			$table = $this->prefix . 'album_cat'; $categories = array();
			foreach ($this->rows($storage,'SELECT * FROM '.$table.' WHERE cat_user_id=0 ORDER BY cat_id FOR UPDATE') as $row) { $categories[(int)$row['cat_id']] = $row; }
			if ($action !== 'new' && !isset($categories[$category])) { throw new AlbumFileCoordinatorFailure('Public category disappeared'); }
			if ($action === 'order')
			{
				$parent = (int)$categories[$category]['cat_parent']; self::ancestry($categories,$parent,$category);
				$siblings = $this->rows($storage,'SELECT cat_id,cat_order FROM '.$table.' WHERE cat_user_id=0 AND cat_parent='.$parent.' ORDER BY cat_order,cat_id FOR UPDATE');
				$position = false; foreach ($siblings as $i=>$row) { if ((int)$row['cat_id'] === $category) { $position = $i; } }
				$destination = $position === false ? -1 : $position + ($move === -15 ? -1 : 1);
				if ($position === false) { throw new AlbumFileCoordinatorFailure('Public sibling category missing'); }
				if ($destination >= 0 && $destination < count($siblings))
				{
					if (count($siblings) > 838860) { throw new AlbumFileCoordinatorFailure('Public category order capacity exceeded'); }
					$swap = $siblings[$position]; $siblings[$position] = $siblings[$destination]; $siblings[$destination] = $swap;
					foreach ($siblings as $i=>$row)
					{
						$order = ($i + 1) * 10;
						if ((int)$row['cat_order'] !== $order) { $storage->sql_query('UPDATE '.$table.' SET cat_order='.$order.' WHERE cat_user_id=0 AND cat_parent='.$parent.' AND cat_id='.(int)$row['cat_id']); }
						$categories[(int)$row['cat_id']]['cat_order'] = self::saved($row['cat_order'],$order);
					}
				}
			}
			else
			{
				self::ancestry($categories,$parent,$category);
				$names = array('cat_title','cat_desc','cat_view_level','cat_upload_level','cat_rate_level','cat_comment_level','cat_edit_level','cat_delete_level','cat_approval'); $assignments = array();
				foreach ($names as $name) { $literal = in_array($name,array('cat_title','cat_desc'),true) ? "'".$storage->sql_escape($values[$name])."'" : (string)$values[$name]; $assignments[] = $name.'='.$literal; }
				$assignments[] = 'cat_parent='.$parent;
				if ($action === 'new')
				{
					$order = 0; foreach ($categories as $row) { $order = max($order,(int)$row['cat_order']); }
					if ($order > 8388597) { throw new AlbumFileCoordinatorFailure('Public category order capacity exceeded'); }
					$storage->sql_query('INSERT INTO '.$table.' SET '.implode(',',$assignments).',cat_order='.($order+10).',cat_user_id=0');
					$category = (int)$storage->sql_nextid(); self::id($category);
					$saved = $this->rows($storage,'SELECT * FROM '.$table.' WHERE cat_id='.$category.' AND cat_user_id=0 FOR UPDATE');
					if (count($saved) !== 1) { throw new AlbumFileCoordinatorFailure('New public category missing'); }
					$categories[$category] = $saved[0];
					$categories[$category]['cat_order'] = self::saved($saved[0]['cat_order'],$order+10);
					$categories[$category]['cat_user_id'] = self::saved($saved[0]['cat_user_id'],0);
				}
				else { $storage->sql_query('UPDATE '.$table.' SET '.implode(',',$assignments).' WHERE cat_id='.$category.' AND cat_user_id=0'); }
				foreach ($names as $name) { $categories[$category][$name] = self::saved($categories[$category][$name],$values[$name]); } $categories[$category]['cat_parent'] = self::saved($categories[$category]['cat_parent'],$parent);
			}
			$after = array(); foreach ($this->rows($storage,'SELECT * FROM '.$table.' WHERE cat_user_id=0 ORDER BY cat_id FOR UPDATE') as $row) { $after[(int)$row['cat_id']] = $row; }
			ksort($categories,SORT_NUMERIC);
			if ($after !== $categories) { throw new AlbumFileCoordinatorFailure('Public category hierarchy readback differs'); }
			$storage->commit(); return $category;
		}
		finally { if ($storage) { $storage->release(); } }
	}
}

function album_admin_category_change($action,$category=null,$parent=0,$values=array(),$move=0)
{
	global $db,$table_prefix,$userdata,$album_data;
	try { $writer = new AlbumAdminCategoryStorage($db,$table_prefix); $result = $writer->change($userdata,$action,$category,$parent,$values,$move); $album_data = array(); return $result; }
	catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
}
