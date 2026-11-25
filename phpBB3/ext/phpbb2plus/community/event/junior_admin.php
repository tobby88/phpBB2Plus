<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class junior_admin implements EventSubscriberInterface
{
	private $auth;
	private $user;
	private $template;
	private $adm;
	private $db;
	private $table;

	public function __construct($auth, $user, $template, $adm, $db, $table)
	{
		$this->auth = $auth;
		$this->user = $user;
		$this->template = $template;
		$this->adm = $adm;
		$this->db = $db;
		$this->table = $table;
	}

	public static function getSubscribedEvents()
	{
		return array('core.page_footer_after' => 'link', 'core.modify_module_row' => 'module', 'core.delete_user_after' => 'depart');
	}

	public function link()
	{
		if ($this->auth->has_delegated_acp() && !$this->auth->acl_get('a_'))
		{
			$this->template->assign_var('U_ACP', append_sid($this->adm, false, true, $this->user->session_id));
		}
	}

	public function depart($event)
	{
		$ids = array_filter(array_map('intval', $event['user_ids']), static function ($id) { return $id > ANONYMOUS; });
		if ($ids) { $this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('user_id', $ids)); }
	}

	public function module($event)
	{
		$source = $event['row'];
		$row = $event['module_row'];
		if (!defined('ADMIN_START') || !$this->auth->has_delegated_acp() || $source['module_class'] !== 'acp' || $row['cat'] ||
			$this->auth->delegated_module($source['module_basename'], $source['module_mode']) || $this->auth->native_module($source['module_auth'])) { return; }
		// Categories and numeric shortcuts must never load an unrestricted module. The source
		// dashboard had a personal Junior Admin summary. Its replacement has no board actions.
		$home = $source['module_basename'] === 'acp_main' && $source['module_mode'] === 'main';
		$row['name'] = '\phpbb2plus\community\acp\junior_overview_module';
		$row['mode'] = $home ? 'main' : 'denied';
		$row['display'] = $home;
		$event['module_row'] = $row;
	}
}
