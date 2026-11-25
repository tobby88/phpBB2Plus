<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\welcome;

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
	private $dispatcher;
	private $config;
	private $text;
	private $paths;

	public function __construct($db, $auth, $visibility, $user, $language, $helper, $template, $root, $extension, $shouts, $dispatcher, $config, $text, $paths)
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
		$this->dispatcher = $dispatcher;
		$this->config = $config;
		$this->text = $text;
		$this->paths = $paths;
	}

	private function recent($forums, $limit)
	{
		if (!$forums || $limit <= 0) { return; }
		$sql = 'SELECT t.topic_id,t.forum_id,t.topic_title,f.forum_name,p.post_id,p.post_time,p.post_username,p.poster_id AS user_id,u.username,u.user_colour
			FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id
			JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_last_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id
			WHERE ' . $this->db->sql_in_set('t.forum_id',$forums) . ' AND t.topic_moved_id=0
			AND t.topic_visibility=' . ITEM_APPROVED . ' AND p.post_visibility=' . ITEM_APPROVED . '
			AND ' . $this->visibility->get_forums_visibility_sql('topic',$forums,'t.') . ' AND ' .
			$this->visibility->get_forums_visibility_sql('post',$forums,'p.') . '
			ORDER BY p.post_id DESC';
		$result = $this->db->sql_query_limit($sql,$limit);
		$rows = array();
		while ($r = $this->db->sql_fetchrow($result)) { $rows[(int) $r['post_id']] = $r; }
		$this->db->sql_freeresult($result);
		$event = $this->dispatcher->trigger_event('phpbb2plus.community.preview_bylines',array('rowset'=>$rows,'post_list'=>array_keys($rows)));
		foreach ($event['rowset'] as $post=>$r)
		{
			$this->template->assign_block_vars('plus_topics',array('TITLE'=>censor_text($r['topic_title']),'FORUM'=>$r['forum_name'],
				'FORUM_ID'=>(int) $r['forum_id'],'LAST_POST'=>$this->user->format_date($r['post_time']),
				'AUTHOR'=>get_username_string('full',(int) $r['user_id'],$r['username'],$r['user_colour'],$r['post_username']),
				'U_TOPIC'=>append_sid($this->root . 'viewtopic.' . $this->extension,'p=' . $post) . '#p' . $post,
				'U_FORUM'=>append_sid($this->root . 'viewforum.' . $this->extension,'f=' . (int) $r['forum_id'])));
		}
	}

	private function members($last_seen, $limit)
	{
		if ($limit <= 0) { return; }
		$sql = 'SELECT user_id,username,user_colour,user_posts,plus_last_login FROM ' . USERS_TABLE .
			' WHERE user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ') AND user_id<>' . ANONYMOUS;
		if ($last_seen)
		{
			$sql .= ' AND plus_last_login>0';
			if (!$this->auth->acl_get('a_')) { $sql .= ' AND user_allow_viewonline=1 AND plus_login_hidden=0'; }
			$sql .= ' ORDER BY plus_last_login DESC,user_id';
		}
		else { $sql .= ' AND user_posts>0 ORDER BY user_posts DESC,user_id'; }
		$result = $this->db->sql_query_limit($sql,max(1,min(100,$limit)));
		while ($r = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars($last_seen ? 'plus_seen' : 'plus_top',array(
				'MEMBER'=>get_username_string('full',$r['user_id'],$r['username'],$r['user_colour']),
				'DETAIL'=>$last_seen ? $this->user->format_date($r['plus_last_login']) : $this->language->lang('TOTAL_POSTS_COUNT',(int) $r['user_posts'])));
		}
		$this->db->sql_freeresult($result);
	}

	private function poll($forums)
	{
		if (!$forums) { return; }
		$result = $this->db->sql_query_limit('SELECT t.topic_id,t.topic_title FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p
			ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id WHERE t.poll_start>0 AND t.topic_moved_id=0
			AND t.topic_status<>' . ITEM_LOCKED . ' AND t.topic_visibility=' . ITEM_APPROVED . ' AND p.post_visibility=' . ITEM_APPROVED .
			' AND ' . $this->db->sql_in_set('t.forum_id',$forums) . ' AND ' . $this->visibility->get_forums_visibility_sql('topic',$forums,'t.') .
			' AND ' . $this->visibility->get_forums_visibility_sql('post',$forums,'p.') . ' ORDER BY t.topic_time DESC,t.topic_id DESC',1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($row) { $this->template->assign_vars(array('PLUS_POLL_TOPIC'=>censor_text($row['topic_title']),
			'U_PLUS_POLL'=>append_sid($this->root . 'viewtopic.' . $this->extension,'t=' . (int) $row['topic_id']))); }
	}

	public function handle()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$forums = array_diff(array_intersect(array_keys($this->auth->acl_getf('f_list', true)),
			array_keys($this->auth->acl_getf('f_read', true))),$this->user->get_passworded_forums());
		$prefs = $this->text->get_array(array('plus_portal_welcome','plus_portal_exclude','plus_portal_poll_forums'));
		$recent = max(0,min(100,(int) $this->config['plus_portal_recent']));
		$this->recent(array_diff($forums,explode(',',(string) $prefs['plus_portal_exclude'])),$recent);
		$this->poll(array_intersect($forums,explode(',',(string) $prefs['plus_portal_poll_forums'])));
		$this->members(true,(int) $this->config['plus_portal_last_seen']);
		$this->members(false,(int) $this->config['plus_portal_top_posters']);
		$mode = (int) $this->config['plus_portal_shouts'];
		$show_shouts = $mode === 1 || ($mode === 2 && $this->user->data['is_registered']);
		if ($show_shouts)
		{
			foreach ($this->shouts->listing(5) as $shout) { $this->template->assign_block_vars('plus_shouts',$shout); }
		}
		$this->template->assign_vars(array('U_PLUS_SHOUTBOX'=>$show_shouts ? $this->helper->route('phpbb2plus_community_shouts') : '',
			'PLUS_WELCOME'=>welcome::render($prefs['plus_portal_welcome'],$this->paths->get_web_root_path()),'S_PLUS_RECENT'=>$recent>0,
			'S_PLUS_STATS'=>(bool) $this->config['plus_portal_stats'],'PLUS_POSTS'=>$this->language->lang('TOTAL_POSTS_COUNT',(int) $this->config['num_posts']),
			'PLUS_TOPICS'=>$this->language->lang('TOTAL_TOPICS',(int) $this->config['num_topics']),
			'PLUS_MEMBERS'=>$this->language->lang('TOTAL_USERS',(int) $this->config['num_users']),
			'PLUS_NEWEST'=>$this->language->lang('NEWEST_USER',get_username_string('full',$this->config['newest_user_id'],$this->config['newest_username'],$this->config['newest_user_colour']))));
		$this->dispatcher->trigger_event('phpbb2plus.community.portal_content', array('forums'=>$forums));
		return $this->helper->render('@phpbb2plus_community/portal.html', $this->language->lang('PLUS_PORTAL'),200,true);
	}
}
