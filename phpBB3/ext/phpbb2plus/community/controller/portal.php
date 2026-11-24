<?php
namespace phpbb2plus\community\controller;

class portal
{
	private $db;
	private $auth;
	private $visibility;
	private $user;
	private $language;
	private $helper;
	private $template;
	private $root;
	private $extension;
	private $shouts;

	public function __construct($db, $auth, $visibility, $user, $language, $helper, $template, $root, $extension, $shouts)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->visibility = $visibility;
		$this->user = $user;
		$this->language = $language;
		$this->helper = $helper;
		$this->template = $template;
		$this->root = $root;
		$this->extension = $extension;
		$this->shouts = $shouts;
	}

	public function handle()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$forums = array_intersect(array_keys($this->auth->acl_getf('f_list', true)), array_keys($this->auth->acl_getf('f_read', true)));
		$forums = array_diff($forums, $this->user->get_passworded_forums());
		if ($forums)
		{
			$sql = 'SELECT t.topic_id, t.forum_id, t.topic_title, t.topic_last_post_time, f.forum_name
				FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id
				WHERE ' . $this->db->sql_in_set('t.forum_id', $forums) . '
					AND ' . $this->visibility->get_forums_visibility_sql('topic', $forums, 't.') . '
					AND t.topic_moved_id = 0
				ORDER BY t.topic_last_post_time DESC, t.topic_id DESC';
			$result = $this->db->sql_query_limit($sql, 10);
			while ($topic = $this->db->sql_fetchrow($result))
			{
				$this->template->assign_block_vars('plus_topics', array(
					'TITLE' => censor_text($topic['topic_title']), 'FORUM' => $topic['forum_name'],
					'LAST_POST' => $this->user->format_date($topic['topic_last_post_time']),
					'U_TOPIC' => append_sid($this->root . 'viewtopic.' . $this->extension, 't=' . (int) $topic['topic_id']),
					'U_FORUM' => append_sid($this->root . 'viewforum.' . $this->extension, 'f=' . (int) $topic['forum_id']),
				));
			}
			$this->db->sql_freeresult($result);
		}
		foreach ($this->shouts->listing(5) as $shout)
		{
			$this->template->assign_block_vars('plus_shouts', $shout);
		}
		$this->template->assign_var('U_PLUS_SHOUTBOX', $this->helper->route('phpbb2plus_community_shouts'));
		return $this->helper->render('@phpbb2plus_community/portal.html', $this->language->lang('PLUS_PORTAL'));
	}
}
