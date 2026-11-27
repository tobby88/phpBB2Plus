<?php
namespace phpbb2plus\community\group;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Plus members could leave named groups after admission closed or withdraw their own application. */
class withdrawal implements EventSubscriberInterface
{
	private $db;
	private $user;
	private $request;
	private $language;
	private $template;
	private $helper;
	private $log;
	private $root;
	private $ext;
	private $groups;

	public function __construct($db, $user, $request, $language, $template, $helper, $log, $root, $ext, $groups)
	{
		$this->db = $db; $this->user = $user; $this->request = $request; $this->language = $language;
		$this->template = $template; $this->helper = $helper; $this->log = $log; $this->root = $root; $this->ext = $ext; $this->groups = $groups;
	}

	public static function getSubscribedEvents()
	{
		return array('core.ucp_display_module_before'=>'selected');
	}

	public function selected($event)
	{
		if ($event['mode'] !== 'membership' || !$this->request->is_set_post('submit') ||
			$this->request->variable('action', '', false, request_interface::POST) !== 'resign') { return; }
		$module = (string) $event['id'];
		foreach ($event['module']->module_ary as $item)
		{
			if ($item['name'] !== 'ucp_groups' || $item['mode'] !== 'membership' ||
				!in_array($module, array('groups', 'ucp_groups', (string) $item['id']), true)) { continue; }
			$id = $this->request->variable('selected', 0, false, request_interface::POST);
			$member = $this->groups->membership($id);
			if ($member && $member['group_leader'])
			{
				$this->language->add_lang('group_leader', 'phpbb2plus/community');
				trigger_error('PLUS_GROUP_STEP_DOWN');
			}
			if ($this->may_withdraw($member, false))
			{
				// The original native selection only opens confirmation. Do not forward its submit fields.
				redirect($this->helper->route('phpbb2plus_community_group_withdraw', array('group'=>$id), false));
			}
			return;
		}
	}

	public function show($group)
	{
		$id = (int) $group;
		$list = append_sid($this->root . 'ucp.' . $this->ext, 'i=ucp_groups&mode=membership', false);
		if ($this->request->is_set_post('cancel')) { return new RedirectResponse($list, 303); }
		$member = $this->groups->membership($id);
		if (!$this->may_withdraw($member, false)) { throw new NotFoundHttpException(); }
		if (!class_exists('p_master', false)) { require_once $this->root . 'includes/functions_module.' . $this->ext; }
		$modules = new \p_master();
		$modules->list_modules('ucp');
		if (!$modules->is_active('ucp_groups', 'membership')) { throw new NotFoundHttpException(); }
		$this->language->add_lang('group_leader', 'phpbb2plus/community');
		$pending = (int) $member['user_pending'];
		$key = 'phpbb2plus/leave/' . $id . '/' . $pending;
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if ($this->request->variable('pending', -1, false, request_interface::POST) !== $pending)
			{
				throw new ConflictHttpException($this->language->lang('PLUS_GROUP_STATE_CHANGED'));
			}
			if (!check_form_key($key) || !confirm_box(true)) { throw new AccessDeniedHttpException($this->language->lang('FORM_INVALID')); }
			if (!function_exists('group_user_del')) { require_once $this->root . 'includes/functions_user.' . $this->ext; }
			$this->db->sql_transaction('begin');
			try
			{
				$fresh = $this->groups->membership($id, true);
				if (!$this->may_withdraw($fresh, true) || (int) $fresh['user_pending'] !== $pending)
				{
					throw new ConflictHttpException($this->language->lang('PLUS_GROUP_STATE_CHANGED'));
				}
				$user = (int) $this->user->data['user_id'];
				if (group_user_del($id, $user, false, $fresh['group_name'], false))
				{
					throw new ConflictHttpException($this->language->lang('PLUS_GROUP_STATE_CHANGED'));
				}
				$this->log->add('user', $user, $this->user->ip, 'LOG_USER_GROUP_RESIGN', false,
					array('reportee_id'=>$user, $fresh['group_name']));
				$this->db->sql_transaction('commit');
			}
			catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
			return new RedirectResponse($list, 303, array('Cache-Control'=>'private, no-store'));
		}
		add_form_key($key);
		$name = htmlspecialchars(htmlspecialchars_decode($member['group_name'], ENT_QUOTES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$title = $this->language->lang($pending ? 'PLUS_GROUP_CANCEL_NAMED' : 'PLUS_GROUP_LEAVE_NAMED', $name);
		confirm_box(false, $title, build_hidden_fields(array('pending'=>$pending)) . $this->template->retrieve_var('S_FORM_TOKEN'),
			'confirm_body.html', $this->helper->route('phpbb2plus_community_group_withdraw', array('group'=>$id), false));
	}

	private function may_withdraw($member, $lock)
	{
		if (!$member || $member['group_leader'] || $member['group_founder_manage'] || $member['group_id'] == $member['default_group']) { return false; }
		if ($member['user_pending']) { return true; }
		if (!in_array((int) $member['group_type'], array(GROUP_CLOSED, GROUP_HIDDEN), true)) { return false; }
		// An ordinary phpBB2 group could only grant rights. A later native Never can make
		// membership restrictive, so leaving such a group stays subject to native policy.
		$sql = 'SELECT a.auth_option_id,a.auth_setting,a.auth_role_id,d.auth_setting AS role_setting FROM ' . ACL_GROUPS_TABLE . ' a
			LEFT JOIN ' . ACL_ROLES_DATA_TABLE . ' d ON d.role_id=a.auth_role_id
			WHERE a.group_id=' . (int) $member['group_id'];
		if (!$lock) { $sql .= ' AND ((a.auth_role_id=0 AND a.auth_option_id>0 AND a.auth_setting=' . ACL_NEVER . ') OR (a.auth_role_id<>0 AND d.auth_setting=' . ACL_NEVER . '))'; }
		$result = $lock ? $this->db->sql_query($sql . ' FOR UPDATE') : $this->db->sql_query_limit($sql, 1);
		$allowed = true;
		while ($row = $this->db->sql_fetchrow($result))
		{
			if (!(int) $row['auth_role_id'] && !(int) $row['auth_option_id']) { continue; }
			$setting = (int) $row['auth_role_id'] ? $row['role_setting'] : $row['auth_setting'];
			if ($setting !== null && (int) $setting === ACL_NEVER) { $allowed = false; break; }
		}
		$this->db->sql_freeresult($result);
		return $allowed;
	}
}
