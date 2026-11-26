<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class category_management
{
	private $db;
	private $auth;
	private $user;
	private $sessions;
	private $artwork;
	private $prefix;
	private $delegations;

	public function __construct($db, $auth, $user, $sessions, $artwork, $prefix)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->sessions = $sessions;
		$this->artwork = $artwork;
		$this->prefix = $prefix . 'plus_arc_';
		$this->delegations = $prefix . 'plus_jr_delegations';
	}

	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function authorize($lock = false)
	{
		$data = $this->user->data;
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($data['session_admin']) || empty($data['is_registered']) ||
			!empty($data['user_perm_from']) || !$this->auth->acl_get('a_plus_arc_categories')) { throw new AccessDeniedHttpException(); }
		if (!$lock) { return; }
		$id = (int) $data['user_id'];
		$sid = $this->db->sql_escape($this->user->session_id);
		$user = $this->rows('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON u.user_id=s.session_user_id
			WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_admin=1 LOCK IN SHARE MODE")[0] ?? null;
		if (!$user || $user['user_perm_from'] || !in_array((int) $user['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { throw new AccessDeniedHttpException(); }
		if ((int) $user['user_type'] === USER_FOUNDER) { return; }
		$auth = clone $this->auth;
		if (!empty($user['plus_jr_delegate']) && method_exists($auth, 'delegated_module'))
		{ $this->rows("SELECT user_id FROM {$this->delegations} WHERE user_id=$id LOCK IN SHARE MODE"); }
		$states = $auth->acl_raw_data($id, array('a_', 'a_plus_arc_categories'), 0)[$id][0] ?? array();
		$right = (int) ($states['a_plus_arc_categories'] ?? ACL_NO);
		if ((int) ($states['a_'] ?? ACL_NO) !== ACL_NEVER && $right !== ACL_NEVER)
		{
			if ($right === ACL_YES) { return; }
			$user['is_registered'] = true;
			$auth->acl($user);
			if (method_exists($auth, 'delegated_module') && $auth->delegated_module('phpbb2plus\arcade\acp\categories_module', 'manage')) { return; }
		}
		throw new AccessDeniedHttpException();
	}

	public function records($lock = false)
	{
		return array_column($this->rows('SELECT * FROM ' . $this->prefix . 'categories ORDER BY cat_id' . ($lock ? ' FOR UPDATE' : '')), null, 'cat_id');
	}

	public function groups()
	{
		return $this->rows('SELECT group_id,group_name FROM ' . GROUPS_TABLE . ' WHERE group_type<>' . GROUP_SPECIAL . ' ORDER BY group_name,group_id');
	}

	public function moderator($id)
	{
		if ((int) $id <= ANONYMOUS) { return ''; }
		return catalog::plain($this->rows('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $id)[0]['username'] ?? '');
	}

	// A converted scope may be an old personal group or an unresolved ID. Its
	// value is preserved unless an administrator explicitly picks a new scope.
	public function group_label($legacy)
	{
		$rows = $this->rows('SELECT a.subject_type,g.group_name FROM ' . $this->prefix . 'grants a LEFT JOIN ' . GROUPS_TABLE .
			" g ON a.subject_type='g' AND a.subject_id=g.group_id WHERE a.legacy_group=" . (int) $legacy);
		return count($rows) === 1 && $rows[0]['group_name'] !== null ? catalog::plain($rows[0]['group_name']) : '';
	}

	private function values($input, $old, $nodes)
	{
		$type = $input['type'];
		$parent = $type === 's' ? (int) $input['parent'] : 0;
		if (!in_array($type, array('p','s','l'), true) || $input['name'] === '' || utf8_strlen($input['name']) > 100 ||
			strlen($input['description']) > 65535 || preg_match('/[\x00-\x1f\x7f]/', $input['name']) ||
			!preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $input['special']) || (int) $input['special'] > 32767)
		{ throw new \DomainException('PLUS_ARC_CAT_INVALID'); }
		if ($type === 's' && ($parent <= 0 || $parent === (int) ($old['cat_id'] ?? 0) || ($nodes[$parent]['cat_type'] ?? '') !== 'p'))
		{ throw new \DomainException('PLUS_ARC_CAT_PARENT'); }
		$values = array('cat_name'=>utf8_encode_ucr(utf8_htmlspecialchars($input['name'])), 'cat_desc'=>utf8_encode_ucr(utf8_htmlspecialchars($input['description'])), 'cat_type'=>$type,
			'cat_parent'=>$parent, 'special_play'=>(int) $input['special']);
		if ($type === 'l' && (strlen($input['description']) > 2048 || preg_match('/[<>"\'\x60]/', $input['description']) || catalog::category_target($values) === ''))
		{ throw new \DomainException('PLUS_ARC_CAT_LINK_INVALID'); }
		$scope = $input['group'];
		if ($scope !== 'keep' || !$old)
		{
			if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $scope)) { throw new \DomainException('PLUS_ARC_CAT_GROUP_INVALID'); }
			$group = (int) $scope;
			if ($group && !$this->rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_id=$group AND group_type<>" . GROUP_SPECIAL . ' LOCK IN SHARE MODE'))
			{ throw new \DomainException('PLUS_ARC_CAT_GROUP_INVALID'); }
			// Older extension versions cannot interpret native groups. Keep the
			// category closed there even if a legacy group has the same numeric ID.
			$values['native_group_id'] = $group;
			$values['group_required'] = $group ? -1 : 0;
		}
		$name = $input['moderator'];
		if ($name !== '')
		{
			$clean = $this->db->sql_escape(utf8_clean_string(utf8_htmlspecialchars($name)));
			$person = $this->rows('SELECT * FROM ' . USERS_TABLE . " WHERE username_clean='$clean' AND user_id>" . ANONYMOUS .
				' AND user_type<>' . USER_IGNORE . ' LOCK IN SHARE MODE')[0] ?? null;
			if (!$person) { throw new \DomainException('PLUS_ARC_CAT_USER_INVALID'); }
			$values['moderator_id'] = (int) $person['user_id'];
			if ($values['moderator_id'] !== (int) ($old['moderator_id'] ?? 0)) { $values['mod_id'] = (int) ($person['plus_legacy_id'] ?? 0); }
		}
		elseif (!$old || !empty($old['moderator_id']) || !empty($input['clear_moderator'])) { $values['moderator_id'] = $values['mod_id'] = 0; }
		if ($input['icon'] !== catalog::plain($old['cat_icon'] ?? ''))
		{
			$icon = $input['icon'];
			if ($icon !== '' && (utf8_strlen($icon) > 255 || $this->artwork->category_path($icon) !== $icon || preg_match('/\s/', $icon)))
			{ throw new \DomainException('PLUS_ARC_CAT_ICON_INVALID'); }
			$values['cat_icon'] = utf8_encode_ucr(utf8_htmlspecialchars($icon));
			$picture = array('icon_storage'=>'','icon_mime'=>'','icon_bytes'=>0);
			foreach ($nodes as $row)
			{
				if ($icon !== '' && catalog::plain($row['cat_icon']) === $icon && !empty($row['icon_storage']))
				{ $picture = array_intersect_key($row, $picture); break; }
			}
			$values += $picture;
		}
		return $values;
	}

	public function change($action, $id, $revision, array $input)
	{
		return $this->sessions->locked(function () use ($action,$id,$revision,$input) {
			$this->authorize(true);
			$table = $this->prefix . 'categories';
			$games = $this->prefix . 'games';
			$before = $this->records(true);
			if (!hash_equals(category_tree::revision(category_tree::shape($before), $before[$id] ?? null), $revision)) { throw new \DomainException('PLUS_ARC_CAT_CHANGED'); }
			if (!in_array($action, array('create','edit','delete','up','down','resync'), true) ||
				(in_array($action, array('create','resync'), true) ? $id !== 0 : ($id < 1 || !isset($before[$id])))) { throw new \DomainException('PLUS_ARC_CAT_CHANGED'); }
			$tree = new category_tree($before);
			if ($tree->repair && $action !== 'resync') { throw new \DomainException('PLUS_ARC_CAT_REPAIR'); }
			$nodes = $tree->rows;
			$old = $before[$id] ?? null;
			$values = in_array($action, array('create','edit'), true) ? $this->values($input, $old, $nodes) : array();
			$topology = in_array($action, array('create','delete','resync'), true) ||
				($action === 'edit' && ($old['cat_type'] !== $values['cat_type'] || (int) $old['cat_parent'] !== $values['cat_parent']));
			$history = $topology ? new category_history($this->db, $this->prefix) : null;
			if ($action === 'create')
			{
				$last_order = 1 + max(array_column($nodes,'cat_order'));
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $values));
				$id = (int) $this->db->sql_nextid();
				if ($id <= 0) { throw new \RuntimeException('Missing new Arcade category ID'); }
				$nodes[$id] = $this->rows("SELECT * FROM $table WHERE cat_id=$id")[0];
				$nodes[$id]['cat_order'] = $last_order;
			}
			elseif ($action === 'edit') { $nodes[$id] = array_replace($nodes[$id], $values); }
			if ($action === 'delete' || ($action === 'edit' && $values['cat_type'] !== 'p'))
			{
				foreach ($tree->children[$id] ?? array() as $child) { $nodes[$child]['cat_type'] = 'p'; $nodes[$child]['cat_parent'] = 0; }
				if ($action === 'delete' || $values['cat_type'] === 'l')
				{
					$this->db->sql_query("UPDATE $games SET cat_id=-1 WHERE cat_id=$id");
					if (!$history && $this->db->sql_affectedrows()) { $history = new category_history($this->db, $this->prefix); }
				}
			}
			if ($action === 'delete') { $this->db->sql_query("DELETE FROM $table WHERE cat_id=$id"); unset($nodes[$id]); }
			if ($action === 'resync')
			{
				$valid = array_keys(array_filter($nodes, static function ($row) { return $row['cat_type'] !== 'l'; }));
				$this->db->sql_query("UPDATE $games SET cat_id=-1 WHERE cat_id IS NULL OR " . $this->db->sql_in_set('cat_id', $valid, true));
			}
			$tree = new category_tree($nodes);
			if ($action === 'up' || $action === 'down') { $tree->move($id, $action === 'up' ? -1 : 1); }
			foreach ($tree->order() as $position => $key)
			{
				$nodes[$key]['cat_order'] = $position;
				$changed = array_filter($nodes[$key], static function ($value, $field) use ($before, $key) {
					if (!isset($before[$key])) { return $field === 'cat_order'; }
					$original = $before[$key][$field];
					return $original === null || $value === null ? $original !== $value : (string) $original !== (string) $value;
				}, ARRAY_FILTER_USE_BOTH);
				if ($changed) { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $changed) . " WHERE cat_id=$key"); }
			}
			if ($history) { $history->recalculate($nodes, $before); }
			return $id;
		});
	}
}
