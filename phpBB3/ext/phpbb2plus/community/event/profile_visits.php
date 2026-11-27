<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\profile\visits;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class profile_visits implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $user;
	private $auth;
	private $template;
	private $language;

	public function __construct($db, $config, $user, $auth, $template, $language)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->template = $template;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.update_session_after' => 'activity', 'core.memberlist_view_profile' => 'profile', 'core.permissions' => 'permission');
	}

	public function permission($event)
	{
		$this->language->add_lang('profile_visits', 'phpbb2plus/community');
		$permissions = $event['permissions'];
		$permissions['u_plus_profile_visits'] = array('lang'=>'ACL_U_PLUS_PROFILE_VISITS', 'cat'=>'profile');
		$event['permissions'] = $permissions;
	}

	public function activity($event)
	{
		$data = $event['session_data'];
		$member = $this->user->data;
		if (empty($this->config['plus_profile_visits_ready']) || !isset($data['session_time'], $member['session_time']) ||
			$event['session_id'] !== $this->user->session_id || empty($member['is_registered'])) { return; }
		$stamp = (int) $data['session_time'];
		if ($stamp <= 0) { return; }
		$previous = (int) $member['session_time'];
		if ($previous <= 0 || $previous > $stamp || $stamp - $previous > max(0, (int) $this->config['session_length']) + 60)
		{ $previous = $stamp; } // An unknown interval or an expired session contributes no online time.

		// Native session updates are rate-limited unless the page changed. Two devices can update
		// together. Credit each page, and only the uncovered part of their elapsed interval.
		$elapsed = "CASE WHEN plus_visit_until<$stamp THEN $stamp - GREATEST(plus_visit_until,$previous) ELSE 0 END";
		$this->db->sql_query('UPDATE ' . USERS_TABLE . '
			SET plus_visit_pages=' . visits::increment('plus_visit_pages', '1') . ',
				plus_visit_seconds=' . visits::increment('plus_visit_seconds', $elapsed) . ",
				plus_visit_until=GREATEST(plus_visit_until,$stamp)
			WHERE user_id=" . (int) $member['user_id'] . ' AND user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ')');
	}

	public function profile($event)
	{
		$member = $event['member'];
		if (empty($this->config['plus_profile_visits_ready']) || empty($this->user->data['is_registered']) ||
			(int) $member['user_id'] <= ANONYMOUS || (int) $member['user_type'] === USER_IGNORE ||
			!$this->auth->acl_get('u_plus_profile_visits')) { return; }

		$this->language->add_lang('profile_visits', 'phpbb2plus/community');
		$start = (int) $member['plus_last_login'];
		$end = (int) $member['plus_visit_until'];
		$this->template->assign_vars(array(
			'S_PLUS_PROFILE_VISITS' => true,
			'PLUS_VISITS_DURATION' => visits::duration($member['plus_visit_seconds'], $this->language),
			'PLUS_VISITS_LAST_DURATION' => $start && $end >= $start ? visits::duration($end - $start, $this->language) : $this->language->lang('NONE'),
			'PLUS_VISITS_LOGINS' => $member['plus_visit_logins'] ?: $this->language->lang('NONE'),
			'PLUS_VISITS_PAGES' => $member['plus_visit_pages'] ?: $this->language->lang('NONE'),
		));
	}
}
