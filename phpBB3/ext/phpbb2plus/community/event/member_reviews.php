<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\account_pruning;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class member_reviews implements EventSubscriberInterface
{
	private $db;
	private $auth;
	private $user;
	private $language;
	private $admin_script;
	private $offered = array();

	public function __construct($db, $auth, $user, $language, $admin_script)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->language = $language;
		$this->admin_script = $admin_script;
	}

	public static function getSubscribedEvents()
	{
		return array('core.memberlist_modify_memberrow_sql' => 'listing', 'core.memberlist_modify_memberrow' => 'member');
	}

	public function can_prune()
	{
		return !empty($this->user->data['is_registered']) && empty($this->user->data['user_perm_from']) &&
			$this->auth->acl_get('a_') && $this->auth->acl_get('a_userdel');
	}

	public function allowed()
	{
		return $this->can_prune() && $this->auth->acl_get('a_user');
	}

	public function targets(array $ids)
	{
		if (!$ids) { return array(); }
		$result = $this->db->sql_query('SELECT u.user_id FROM ' . USERS_TABLE . ' u WHERE ' .
			$this->db->sql_in_set('u.user_id', array_map('intval', $ids)) . ' AND ' .
			account_pruning::ordinary_scope($this->db, $this->user->data['user_id']));
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return account_pruning::without_admin_grants($this->auth, $rows);
	}

	public function url($id, $html)
	{
		$query = array('i' => 'users', 'mode' => 'overview', 'u' => (int) $id);
		return append_sid($this->admin_script, $query, $html, $this->user->session_id) . '#user_delete';
	}

	public function prune_url($rule, $days)
	{
		return append_sid($this->admin_script, array('i' => '-phpbb2plus-community-acp-user_pruning_module',
			'mode' => 'manage', 'rule' => (int) $rule, 'days' => (int) $days), false, $this->user->session_id);
	}

	public function listing($event)
	{
		if (!$this->allowed() || !in_array($event['mode'], array('', 'searchuser'), true)) { return; }
		$this->offered = $this->targets($event['user_list']);
		if ($this->offered) { $this->language->add_lang('info_acp_member_directory', 'phpbb2plus/community'); }
	}

	public function member($event)
	{
		$id = (int) $event['user_id'];
		if (!isset($this->offered[$id])) { return; }
		$row = $event['memberrow'];
		$row['U_PLUS_ACCOUNT_REVIEW'] = $this->url($id, true);
		$event['memberrow'] = $row;
	}
}
