<?php
namespace phpbb2plus\downloads\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	private $helper;
	private $template;
	private $language;
	private $db;
	private $prefix;

	public function __construct($helper, $template, $language, $db, $prefix)
	{
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->db = $db;
		$this->prefix = $prefix;
	}

	static public function getSubscribedEvents()
	{
		return array('core.page_header' => 'header', 'core.permissions' => 'permissions', 'core.delete_user_before' => array('depart', 10));
	}

	public function header()
	{
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$this->template->assign_var('U_PLUS_DOWNLOADS', $this->helper->route('phpbb2plus_downloads_index'));
	}

	public function permissions($event)
	{
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$permissions = $event['permissions'];
		$permissions['a_plus_downloads'] = array('lang' => 'ACL_A_PLUS_DOWNLOADS', 'cat' => 'misc');
		$event['permissions'] = $permissions;
	}

	public function depart($event)
	{
		foreach (array_keys($event['user_rows']) as $user)
		{
			$id = (int) $user;
			if ($id <= ANONYMOUS) { continue; }
			foreach (array('files','comments','visitors','votes') as $table)
			{ $this->db->sql_query('UPDATE ' . $this->prefix . "$table SET owner_id=0 WHERE owner_id=$id"); }
			$this->db->sql_query('DELETE FROM ' . $this->prefix . "grants WHERE subject_type='u' AND subject_id=$id");
		}
	}
}
