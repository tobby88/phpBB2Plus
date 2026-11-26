<?php
namespace phpbb2plus\arcade\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	private $language;
	private $template;
	private $helper;
	private $db;
	private $prefix;
	private $protocol;

	public function __construct($language, $template, $helper, $db, $prefix, $protocol)
	{
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->db = $db;
		$this->prefix = $prefix;
		$this->protocol = $protocol;
	}

	static public function getSubscribedEvents()
	{
		return array('core.page_header' => 'header', 'core.permissions' => 'permissions', 'core.delete_user_before' => array('depart', 10),
			'core.index_modify_page_title' => array('index_protocol', 10));
	}

	public function index_protocol()
	{
		$endpoint = $this->protocol->index_endpoint();
		if ($endpoint !== '')
		{
			$this->protocol->handle($endpoint)->send();
			garbage_collection();
			exit_handler();
		}
	}

	public function header()
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		$this->template->assign_var('U_PLUS_ARCADE', $this->helper->route('phpbb2plus_arcade_index'));
	}

	public function permissions($event)
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		$perms = $event['permissions'];
		$perms['a_plus_arcade'] = array('lang' => 'ACL_A_PLUS_ARCADE', 'cat' => 'misc');
		$perms['a_plus_arc_scores'] = array('lang' => 'ACL_A_PLUS_ARC_SCORES', 'cat' => 'misc');
		$this->language->add_lang('info_acp_arc_categories', 'phpbb2plus/arcade');
		$perms['a_plus_arc_categories'] = array('lang' => 'ACL_A_PLUS_ARC_CATEGORIES', 'cat' => 'misc');
		$perms['m_plus_arcade'] = array('lang' => 'ACL_M_PLUS_ARCADE', 'cat' => 'misc');
		$event['permissions'] = $perms;
	}

	public function depart($event)
	{
		foreach ($event['user_rows'] as $id => $user)
		{
			$id = (int) $id;
			if ($id <= ANONYMOUS) { continue; }
			$name = $event['retain_username'] ? $user['username'] : '';
			foreach (array('scores' => 'player', 'all_scores' => 'player', 'monthly' => 'highscore_player', 'comments' => 'comment_username') as $table => $field)
			{
				$update = array('owner_id' => 0, 'author_removed' => 1, $field => $name);
				if ($table === 'all_scores') { $update['player_name'] = $name; }
				$this->db->sql_query('UPDATE ' . $this->prefix . $table . ' SET ' . $this->db->sql_build_array('UPDATE', $update) . ' WHERE owner_id=' . $id);
			}
			foreach (array('saves','favorites','invites','players','wallets','comment_receipts','proposals') as $table) { $this->db->sql_query('DELETE FROM ' . $this->prefix . $table . ' WHERE owner_id=' . $id); }
			$this->db->sql_query('DELETE FROM ' . $this->prefix . "tour_notices WHERE sender_id=$id OR recipient_id=$id");
			foreach (array('ratings' => 'owner_id', 'bans' => 'owner_id', 'log' => 'owner_id', 'tour_plays' => 'owner_id',
				'comments' => 'editor_id', 'tour_games' => 'winner_id') as $table => $field)
			{ $this->db->sql_query('UPDATE ' . $this->prefix . "$table SET $field=0 WHERE $field=$id"); }
			foreach (array('categories' => array('moderator_id','last_user_id'), 'games' => array('winner_id','all_winner_id'), 'tours' => array('founder_id','winner_id')) as $table => $fields)
			{
				foreach ($fields as $field) { $this->db->sql_query('UPDATE ' . $this->prefix . "$table SET $field=0 WHERE $field=$id"); }
			}
			$this->db->sql_query('DELETE FROM ' . $this->prefix . 'members WHERE user_id=' . $id);
			$this->db->sql_query('DELETE FROM ' . $this->prefix . 'launches WHERE user_id=' . $id);
			$this->db->sql_query('DELETE FROM ' . $this->prefix . "grants WHERE subject_type='u' AND subject_id=" . $id);
		}
	}
}
