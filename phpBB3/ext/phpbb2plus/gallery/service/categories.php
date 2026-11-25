<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class categories
{
	const DEFAULTS = array('view' => '-1', 'upload' => '0', 'rate' => '0', 'comment' => '0', 'edit' => '0', 'delete' => '2', 'approval' => '0');
	private $db;
	private $hierarchy;
	private $access;
	private $auth;
	private $user;
	private $table;
	private $log;

	public function __construct($db, $hierarchy, $access, $auth, $user, $table, $log)
	{
		$this->db = $db;
		$this->hierarchy = $hierarchy;
		$this->access = $access;
		$this->auth = $auth;
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
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['is_registered']) ||
			empty($this->user->data['session_admin']) || !empty($this->user->data['user_perm_from']) ||
			!$this->auth->acl_gets('a_plus_gallery', 'a_plus_album_categories')) { throw new AccessDeniedHttpException(); }
		return $this->hierarchy->tree(0);
	}

	private function authorize_write()
	{
		$actor = (int) $this->user->data['user_id'];
		$sid = $this->db->sql_escape($this->user->session_id);
		// Older installations and boards without the optional Junior Admin migration have no delegate marker.
		$marker = array_key_exists('plus_jr_delegate', $this->user->data) ? ',u.plus_jr_delegate' : '';
		$result = $this->db->sql_query('SELECT u.user_id,u.user_type,u.user_perm_from,u.user_permissions' . $marker . ' FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
			" s ON s.session_user_id=u.user_id WHERE u.user_id=$actor AND s.session_id='$sid' AND s.session_admin=1");
		$account = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$account || (int) $account['user_perm_from'] || (int) $account['user_type'] !== (int) $this->user->data['user_type'] ||
			!in_array((int) $account['user_type'], array(USER_NORMAL, USER_FOUNDER), true))
		{ throw new AccessDeniedHttpException(); }
		if ((int) $account['user_type'] === USER_FOUNDER) { return; }
		$permissions = $this->auth->acl_raw_data($actor, array('a_', 'a_plus_gallery', 'a_plus_album_categories'), 0);
		$row = $permissions[$actor][0] ?? array();
		if ((int) ($row['a_'] ?? ACL_NO) === ACL_NEVER) { throw new AccessDeniedHttpException(); }
		$narrow = (int) ($row['a_plus_album_categories'] ?? ACL_NO);
		if ((int) ($row['a_plus_gallery'] ?? ACL_NO) === ACL_YES || $narrow === ACL_YES) { return; }
		if ($narrow !== ACL_NEVER && !empty($account['plus_jr_delegate']) && method_exists($this->auth, 'delegated_module'))
		{
			// A migrated module grant exists only for this ACP request. Never materialize it in native ACL tables.
			$account['is_registered'] = true;
			$this->auth->acl($account);
			if ($this->auth->delegated_module('phpbb2plus\gallery\acp\categories_module', 'manage')) { return; }
		}
		throw new AccessDeniedHttpException();
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
			$tree = $this->tree();
			$this->authorize_write();
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
