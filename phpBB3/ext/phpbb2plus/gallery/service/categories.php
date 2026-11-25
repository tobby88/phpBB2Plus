<?php
namespace phpbb2plus\gallery\service;

class categories
{
	const DEFAULTS = array('view' => '-1', 'upload' => '0', 'rate' => '0', 'comment' => '0', 'edit' => '0', 'delete' => '2', 'approval' => '0');
	private $db;
	private $hierarchy;
	private $access;
	private $admin;
	private $user;
	private $table;
	private $log;

	public function __construct($db, $hierarchy, $access, $admin, $user, $table, $log)
	{
		$this->db = $db;
		$this->hierarchy = $hierarchy;
		$this->access = $access;
		$this->admin = $admin;
		$this->user = $user;
		$this->table = $table;
		$this->log = $log;
	}

	public static function options($name)
	{
		if ($name === 'approval') { return array(0 => 'NONE', 2 => 'MODS', 1 => 'ADMINS'); }
		$levels = array(-1 => 'GUESTS', 0 => 'MEMBERS', 3 => 'ASSIGNED', 2 => 'MODS', 1 => 'ADMINS');
		if ($name === 'edit' || $name === 'delete') { unset($levels[-1]); }
		return $levels;
	}

	public function tree()
	{
		$this->admin->authorize('categories');
		return $this->hierarchy->tree(0);
	}

	public function review($tree, $id, $action)
	{
		if (!in_array($action, array('add','edit','up','down','delete'), true) ||
			(!isset($tree[$id]) && !($action === 'add' && $id === 0))) { throw new \DomainException('PLUS_ALBUM_PUBLIC_MISSING'); }
		return $this->hierarchy->review($tree, $id, $action);
	}

	public function choices($tree, $id, $action)
	{
		return $action === 'add' ? $tree : $this->hierarchy->destinations($tree, $id);
	}

	public function change($id, $action, $fields, $revision)
	{
		return $this->access->write(function () use ($id, $action, $fields, $revision) {
			$this->admin->authorize('categories', true);
			$tree = $this->hierarchy->tree(0);
			$review = $this->review($tree, $id, $action);
			if (!hash_equals($review['state'], $revision)) { throw new \DomainException('PLUS_ALBUM_TREE_CHANGED'); }
			$this->apply($tree, $id, $action, $fields);
			$label = $action === 'add' || $action === 'edit' ? $fields['title'] : $tree[$id]['title'];
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip,
				'LOG_PLUS_ALBUM_PUBLIC_' . strtoupper($action), false, array(access::encode_label($label)));
		});
	}

	private function apply($tree, $id, $action, $fields)
	{
		if ($action === 'up' || $action === 'down')
		{
			$this->hierarchy->reorder($tree, $id, $action);
			return;
		}
		$choices = $this->choices($tree, $id, $action);
		if ($action === 'delete')
		{
			$dest = $fields['destination'];
			if ($dest !== 'remove' && !isset($choices[$dest])) { throw new \DomainException('PLUS_ALBUM_PUBLIC_DEST'); }
			$this->hierarchy->remove($tree[$id], $dest);
			return;
		}
		if ($fields['parent'] !== '0' && !isset($choices[$fields['parent']])) { throw new \DomainException('PLUS_ALBUM_PUBLIC_DEST'); }
		if ($fields['title'] === '' || utf8_strlen($fields['title']) > 255 || utf8_strlen($fields['description']) > 65535)
		{ throw new \DomainException('PLUS_ALBUM_PERSONAL_BAD_TEXT'); }
		$data = array('title' => access::encode_label($fields['title']), 'description' => access::encode_label($fields['description']),
			'label_encoded' => 1, 'parent_id' => (int) $fields['parent']);
		foreach (self::DEFAULTS as $name => $default)
		{
			$column = $name === 'approval' ? $name : $name . '_level';
			$value = $fields[$name];
			if (!isset(self::options($name)[$value]) && !($action === 'edit' && $value === (string) $tree[$id][$column]))
			{ throw new \DomainException('PLUS_ALBUM_BAD_LEVEL'); }
			$data[$column] = (int) $value;
		}
		if ($action === 'add')
		{
			$data += array('owner_id' => 0, 'source_owner' => 0, 'sort_order' => $this->hierarchy->next_order($tree, null));
			$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $data));
		}
		else
		{
			// Category-specific user, group and moderator grants belong to their own permission module.
			$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', $data) . ' WHERE cat_id=' . (int) $id);
		}
	}
}
