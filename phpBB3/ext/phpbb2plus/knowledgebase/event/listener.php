<?php
namespace phpbb2plus\knowledgebase\event;

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
		return array('core.page_header' => 'header', 'core.permissions' => 'permissions', 'core.delete_user_before' => array('depart', 10),
			'core.delete_topics_before_query' => 'unlink_topics');
	}

	public function header()
	{
		$this->language->add_lang('knowledgebase', 'phpbb2plus/knowledgebase');
		$this->template->assign_var('U_PLUS_KB', $this->helper->route('phpbb2plus_kb_index'));
	}

	public function permissions($event)
	{
		$permissions = $event['permissions'];
		$permissions['a_plus_kb'] = array('lang' => 'ACL_A_PLUS_KB', 'cat' => 'misc');
		$permissions['m_plus_kb'] = array('lang' => 'ACL_M_PLUS_KB', 'cat' => 'misc');
		$event['permissions'] = $permissions;
	}

	public function depart($event)
	{
		foreach ($event['user_rows'] as $id => $row)
		{
			$id = (int) $id;
			if ($id <= ANONYMOUS) { continue; }
			$name = $event['retain_username'] ? htmlspecialchars_decode($row['username'], ENT_QUOTES) : '';
			$this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET ' . $this->db->sql_build_array('UPDATE',
				array('author_id' => ANONYMOUS, 'author_removed' => 1, 'author_name' => $name)) . ' WHERE author_id=' . $id);
			$this->db->sql_query('UPDATE ' . $this->prefix . 'votes SET user_id=' . ANONYMOUS . ' WHERE user_id=' . $id);
		}
	}

	public function unlink_topics($event)
	{
		$ids = array_map('intval', $event['topic_ids']);
		if ($ids) { $this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET topic_id=0 WHERE ' . $this->db->sql_in_set('topic_id', $ids)); }
	}
}
