<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class account_registration implements EventSubscriberInterface
{
	private $db;
	private $language;
	private $auth;
	private $user;
	private $delegations;
	private $pending = 0;
	private $shutdown_registered = false;

	public function __construct($db, $language, $auth, $user, $prefix)
	{
		$this->db = $db;
		$this->language = $language;
		$this->auth = $auth;
		$this->user = $user;
		$this->delegations = $prefix . 'plus_jr_delegations';
	}

	public static function getSubscribedEvents()
	{
		return array('core.permissions' => 'permissions',
			'core.user_add_modify_data' => array('begin_member', PHP_INT_MIN),
			'core.user_add_after' => array('finish_member', PHP_INT_MIN),
			'core.garbage_collection' => 'abort');
	}

	public function permissions($event)
	{
		$this->language->add_lang('info_acp_registration', 'phpbb2plus/community');
		$options = $event['permissions'];
		$options['a_plus_user_register'] = array('lang' => 'ACL_A_PLUS_USER_REGISTER', 'cat' => 'user_group');
		$event['permissions'] = $options;
	}

	private function row($sql)
	{
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	// The ACP, UCP and CLI all call user_add(). Hold its member count row until
	// profile fields, group membership and newest-member statistics are complete.
	private function lock_registrations()
	{
		$this->row("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='num_users' FOR UPDATE");
	}

	public function begin_member($event)
	{
		if (!in_array((int) $event['user_row']['user_type'], array(USER_NORMAL, USER_INACTIVE), true)) { return; }
		$this->db->sql_transaction('begin');
		++$this->pending;
		if (!$this->shutdown_registered)
		{
			register_shutdown_function(array($this, 'abort'));
			$this->shutdown_registered = true;
		}
		$this->lock_registrations();
		$data = $event['sql_ary'];
		$name = $this->db->sql_escape($data['username_clean']);
		$email = $this->db->sql_escape($data['user_email']);
		$reuse = $this->row("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='allow_emailreuse' LOCK IN SHARE MODE");
		$error = '';
		if ($this->row("SELECT user_id FROM " . USERS_TABLE . " WHERE username_clean='$name' LIMIT 1 LOCK IN SHARE MODE"))
		{ $error = 'USERNAME_TAKEN_USERNAME'; }
		elseif ($email !== '' && empty($reuse['config_value']) &&
			$this->row("SELECT user_id FROM " . USERS_TABLE . " WHERE user_email='$email' LIMIT 1 LOCK IN SHARE MODE"))
		{ $error = 'EMAIL_TAKEN_EMAIL'; }
		if ($error !== '')
		{
			$this->abort();
			if (defined('IN_ADMIN') && IN_ADMIN) { throw new \DomainException($error); }
			$this->language->add_lang('ucp');
			if (PHP_SAPI === 'cli') { throw new \phpbb\exception\runtime_exception($this->language->lang($error)); }
			trigger_error($this->language->lang($error), E_USER_WARNING);
		}
	}

	public function finish_member($event)
	{
		if ($this->pending && in_array((int) $event['user_row']['user_type'], array(USER_NORMAL, USER_INACTIVE), true))
		{
			$this->db->sql_transaction('commit');
			--$this->pending;
		}
	}

	public function abort()
	{
		if ($this->pending)
		{
			$this->pending = 0;
			$this->db->sql_transaction('rollback');
		}
	}

	public function create($data, $password_hash, $fields, $new_member, $log)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->lock_registrations();
			$this->authorize(true);
			$group = $this->row("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL . ' LOCK IN SHARE MODE');
			if (!$group) { throw new \DomainException('ACP_PLUS_REGISTER_FAILED'); }
			if (!$this->row('SELECT style_id FROM ' . STYLES_TABLE . ' WHERE style_active=1 AND style_id=' . (int) $data['style'] . ' LOCK IN SHARE MODE'))
			{ throw new \DomainException('ACP_PLUS_REGISTER_STYLE'); }
			if (!$this->row('SELECT lang_id FROM ' . LANG_TABLE . " WHERE lang_iso='" . $this->db->sql_escape($data['lang']) . "' LOCK IN SHARE MODE"))
			{ throw new \DomainException('WRONG_DATA_LANG'); }
			$id = user_add(array('username' => $data['username'], 'user_password' => $password_hash, 'user_email' => $data['email'],
				'group_id' => (int) $group['group_id'], 'user_type' => USER_NORMAL, 'user_new' => (int) $new_member,
				'user_lang' => $data['lang'], 'user_style' => $data['style'], 'user_timezone' => $data['tz'],
				'user_dateformat' => $data['dateformat']), $fields);
			if (!$id) { throw new \DomainException('ACP_PLUS_REGISTER_FAILED'); }
			$log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_PLUS_USER_REGISTER', false, array($data['username']));
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e)
		{
			$this->abort();
			if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
			throw $e;
		}
	}

	public function authorize($write = false)
	{
		$right = 'a_plus_user_register';
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['is_registered']) ||
			empty($this->user->data['session_admin']) || !empty($this->user->data['user_perm_from']) || !$this->auth->acl_get($right))
		{ throw new \DomainException('NO_AUTH_OPERATION'); }
		if (!$write) { return; }
		$id = (int) $this->user->data['user_id'];
		$sid = $this->db->sql_escape($this->user->session_id);
		$actor = $this->row('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id
			WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_admin=1 LOCK IN SHARE MODE");
		if (!$actor || $actor['user_perm_from'] || !in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true))
		{ throw new \DomainException('NO_AUTH_OPERATION'); }
		if ((int) $actor['user_type'] === USER_FOUNDER) { return; }
		if (!empty($actor['plus_jr_delegate'])) { $this->row("SELECT user_id FROM {$this->delegations} WHERE user_id=$id LOCK IN SHARE MODE"); }
		$raw = $this->auth->acl_raw_data($id, array('a_', $right), 0);
		$options = $raw[$id][0] ?? array();
		if ((int) ($options['a_'] ?? ACL_NO) !== ACL_NEVER && (int) ($options[$right] ?? ACL_NO) !== ACL_NEVER)
		{
			if ((int) ($options[$right] ?? ACL_NO) === ACL_YES) { return; }
			$actor['is_registered'] = true;
			$this->auth->acl($actor);
			if ($this->auth->delegated_module('phpbb2plus\community\acp\registration_module', 'create')) { return; }
		}
		throw new \DomainException('NO_AUTH_OPERATION');
	}
}
