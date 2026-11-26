<?php
namespace phpbb2plus\community\controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class topic_readers
{
	private $reader;
	private $activity;
	private $db;
	private $table;
	private $auth;
	private $config;
	private $user;
	private $language;
	private $request;
	private $template;
	private $pagination;
	private $helper;

	public function __construct($reader, $activity, $db, $prefix, $auth, $config, $user, $language, $request, $template, $pagination, $helper)
	{
		$this->reader = $reader;
		$this->activity = $activity;
		$this->db = $db;
		$this->table = $prefix . 'plus_topic_readers';
		$this->auth = $auth;
		$this->config = $config;
		$this->user = $user;
		$this->language = $language;
		$this->request = $request;
		$this->template = $template;
		$this->pagination = $pagination;
		$this->helper = $helper;
	}

	public function show($topic_id)
	{
		if (empty($this->config['plus_topic_readers'])) { throw new NotFoundHttpException(); }
		$route = 'phpbb2plus_community_topic_readers';
		$params = array('topic_id' => (int) $topic_id);
		$mode = $this->request->variable('mode', 'joined');
		$direction = strtoupper($this->request->variable('order', $mode === 'topten' ? 'DESC' : 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
		$start = max(0, $this->request->variable('start', 0));
		$filters = $params + array('mode' => $mode, 'order' => $direction, 'start' => $start);
		if (empty($this->user->data['is_registered'])) { login_box($this->helper->route($route, $filters, false)); }
		if (!$this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel')) { throw new \phpbb\exception\http_exception(403, 'NO_VIEW_USERS'); }
		$topic = $this->reader->open($topic_id, 0, $route, $filters, true, false);
		if ((int) $topic['topic_visibility'] !== ITEM_APPROVED || $topic['plus_legacy_removed']) { throw new NotFoundHttpException(); }
		$this->language->add_lang('topic_readers', 'phpbb2plus/community');

		$sorts = array('joined' => array('u.user_regdate', 'SORT_JOINED'), 'username' => array('u.username_clean', 'USERNAME'),
			'topic_time' => array('r.last_read', 'PLUS_READERS_LAST'), 'topic_count' => array('r.visit_count', 'VIEWS'),
			'topten' => array('u.user_posts', 'PLUS_READERS_TOP_TEN'));
		if ($this->auth->acl_get('a_user')) { $sorts['email'] = array('u.user_email', 'EMAIL_ADDRESS'); }
		$result = $this->db->sql_query('SELECT field_id FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_ident='phpbb_website'
			AND field_active=1 AND field_show_profile=1 AND field_no_view=0 AND field_hide=0");
		if ($this->db->sql_fetchfield('field_id')) { $sorts['website'] = array('pf.pf_phpbb_website', 'WEBSITE'); }
		$this->db->sql_freeresult($result);
		if (!isset($sorts[$mode])) { $mode = 'joined'; }

		// A topic can move or be merged across forums. Aggregate only the visits made in forums
		// the current reader can still open, including this session's forum passwords.
		$forums = $this->activity->forums();
		if (!$forums) { throw new NotFoundHttpException(); }
		$from = ' FROM (SELECT user_id,MAX(last_read) AS last_read,LEAST(2147483647,SUM(visit_count)) AS visit_count
			FROM ' . $this->table . ' WHERE topic_id=' . (int) $topic_id . ' AND ' . $this->db->sql_in_set('forum_id', $forums) .
			' GROUP BY user_id) r JOIN ' . USERS_TABLE . ' u ON u.user_id=r.user_id';
		if ($mode === 'website') { $from .= ' LEFT JOIN ' . PROFILE_FIELDS_DATA_TABLE . ' pf ON pf.user_id=u.user_id'; }
		$from .= ' WHERE (u.user_id=' . ANONYMOUS . ' OR u.user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . '))';
		if (!$this->auth->acl_get('u_viewonline'))
		{
			$from .= ' AND (u.user_id IN (' . ANONYMOUS . ',' . (int) $this->user->data['user_id'] . ') OR (u.user_allow_viewonline=1 AND u.plus_login_hidden=0))';
		}
		if ($mode === 'topten') { $from .= ' AND u.user_id<>' . ANONYMOUS; }
		$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		$limit = max(1, (int) $this->config['topics_per_page']);
		if ($mode === 'topten') { $limit = 10; $total = min($total, $limit); }
		$start = min(intdiv(max(0, $total - 1), $limit), intdiv($start, $limit)) * $limit;
		$result = $this->db->sql_query_limit('SELECT u.user_id,u.username,u.user_colour,u.user_regdate,u.user_posts,r.last_read,r.visit_count' . $from .
			' ORDER BY ' . $sorts[$mode][0] . ' ' . $direction . ',u.user_id ASC', $limit, $start);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$guest = (int) $row['user_id'] === ANONYMOUS;
			$this->template->assign_block_vars('readers', array('ID' => (int) $row['user_id'],
				'NAME' => $guest ? $this->language->lang('PLUS_READERS_GUESTS') : get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']),
				'JOINED' => !$guest && $row['user_regdate'] ? $this->user->format_date($row['user_regdate']) : '',
				'LAST' => $row['last_read'] ? $this->user->format_date($row['last_read']) : $this->language->lang('NEVER'),
				'COUNT' => (int) $row['visit_count'], 'POSTS' => $guest ? '' : (int) $row['user_posts']));
		}
		$this->db->sql_freeresult($result);
		foreach ($sorts as $key => $sort)
		{
			$this->template->assign_block_vars('sorts', array('KEY' => $key, 'LABEL' => $this->language->lang($sort[1]), 'SELECTED' => $mode === $key));
		}
		$this->pagination->generate_template_pagination($this->helper->route($route, $params + array('mode' => $mode, 'order' => $direction)),
			'pagination', 'start', $total, $limit, $start);
		$this->template->assign_vars(array('READER_TOPIC' => censor_text($topic['topic_title']), 'S_READER_DESC' => $direction === 'DESC',
			'U_READER_TOPIC' => append_sid(generate_board_url() . '/viewtopic.php', 't=' . (int) $topic_id),
			'U_READERS_SORT' => $this->helper->route($route, $params), 'READER_TOTAL' => $total));
		$response = $this->helper->render('@phpbb2plus_community/topic_readers.html', $this->language->lang('PLUS_READERS'));
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->setVary('Cookie', false);
		return $response;
	}
}
