<?php
namespace phpbb2plus\calendar\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Supply civil-day matches to phpBB's ordinary post search, including its bylines and formatter. */
class day_search implements EventSubscriberInterface
{
	private $db;
	private $request;
	private $user;
	private $auth;
	private $visibility;
	private $language;
	private $template;
	private $pagination;
	private $day;
	private $condition;
	private $direction;

	public function __construct($db, $request, $user, $auth, $visibility, $language, $template, $pagination)
	{
		$this->db=$db; $this->request=$request; $this->user=$user; $this->auth=$auth; $this->visibility=$visibility;
		$this->language=$language; $this->template=$template; $this->pagination=$pagination;
	}

	public static function getSubscribedEvents()
	{
		return array('core.search_modify_submit_parameters'=>'select_day', 'core.search_modify_param_before'=>'posts',
			'core.search_modify_param_after'=>'heading', 'core.search_backend_search_after'=>'matches',
			'core.search_modify_url_parameters'=>'navigation', 'core.search_get_posts_data'=>'order');
	}

	public function select_day($event)
	{
		if ($event['search_id'] !== 'mini_cal') { return; }
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$zone = $this->user->create_datetime()->getTimezone();
		$date = $this->request->variable('date','');
		$stamp = $this->request->variable('d','');
		if ($date === '' && preg_match('/^[0-9]{1,10}$/D',$stamp))
		{ $date = (new \DateTimeImmutable('@' . $stamp))->setTimezone($zone)->format('Y-m-d'); }
		$day = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date) ? \DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone) : false;
		if (!$day || $day->format('Y-m-d') !== $date || (int) $day->format('Y') < 1971 ||
			(int) $day->format('Y') > 2069 || $day > new \DateTimeImmutable('today',$zone))
		{
			send_status_line(404, 'Not Found');
			trigger_error('PLUS_MINI_UNKNOWN_DAY');
		}
		$this->day = $day;
		$event['keywords'] = $event['author'] = '';
		$event['author_id'] = 0;
	}

	public function posts($event)
	{
		if (!$this->day) { return; }
		$event['show_results'] = 'posts';
		$sort = $event['sort_by_sql'];
		$sort['t'] = 'p.post_time';
		$sort['s'] = 'p.post_subject';
		$event['sort_by_sql'] = $sort;
	}

	public function heading($event)
	{
		if (!$this->day) { return; }
		global $phpbb_root_path, $phpEx;
		$title = $this->language->lang('PLUS_MINI_POSTS_DAY',$this->user->format_date((int) $this->day->format('U'),'l, j F Y',true));
		$event['l_search_title'] = $title;
		$this->template->alter_block_array('navlinks',array('BREADCRUMB_NAME'=>$title,
			'U_BREADCRUMB'=>append_sid($phpbb_root_path . 'search.' . $phpEx,
				'search_id=mini_cal&amp;date=' . $this->day->format('Y-m-d'))),true,'change');
	}

	public function matches($event)
	{
		if (!$this->day) { return; }
		$excluded = $event['ex_fid_ary'];
		$forums = array_diff(array_intersect(array_keys($this->auth->acl_getf('f_list',true)),
			array_keys($this->auth->acl_getf('f_read',true)), array_keys($this->auth->acl_getf('f_search',true))), $excluded, $this->user->get_passworded_forums());
		$event['id_ary'] = array();
		$event['total_match_count'] = $event['start'] = 0;
		if (!$forums) { return; }
		// The next local midnight may be 23 or 25 hours after the first.
		$start = (int) $this->day->format('U');
		$end = (int) $this->day->modify('+1 day')->setTime(0,0)->format('U');
		if ((int) $event['sort_days'] > 0) { $start = max($start, time() - (int) $event['sort_days'] * 86400); }
		if ($start >= $end) { return; }
		$this->condition = "p.post_time>=$start AND p.post_time<$end AND p.forum_id=t.forum_id AND t.topic_moved_id=0 AND " .
			$this->db->sql_in_set('p.forum_id',$forums) . ' AND (' . $event['m_approve_posts_fid_sql'] . ') AND (' .
			$this->visibility->get_global_visibility_sql('topic',$excluded,'t.') . ')';
		$from = ' FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id';
		$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from . ' WHERE ' . $this->condition);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		if (!$count) { return; }
		$limit = max(1,(int) $event['per_page']);
		$offset = $this->pagination->validate_start(max(0,$this->request->variable('start',0)), $limit, $count);
		$key = $event['sort_key'];
		$sort = $event['sort_by_sql'][$key] ?? 'p.post_time';
		if ($key === 'a') { $from .= ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id'; }
		if ($key === 'f') { $sort = 'p.forum_id'; }
		$this->direction = $event['sort_dir'] === 'a' ? ' ASC' : ' DESC';
		$result = $this->db->sql_query_limit('SELECT p.post_id' . $from . ' WHERE ' . $this->condition .
			' ORDER BY ' . $sort . $this->direction . ',p.post_id' . $this->direction, $limit, $offset);
		$event['id_ary'] = array_column($this->db->sql_fetchrowset($result),'post_id');
		$this->db->sql_freeresult($result);
		$event['start'] = $offset;
		$event['total_match_count'] = $count;
	}

	public function order($event)
	{
		if (!$this->day || !$this->direction) { return; }
		$query = $event['sql_array'];
		$query['ORDER_BY'] .= ',p.post_id' . $this->direction;
		$event['sql_array'] = $query;
	}

	public function navigation($event)
	{
		if (!$this->day) { return; }
		$event['search_id'] = 'mini_cal';
		$event['u_search'] .= '&amp;search_id=mini_cal&amp;date=' . $this->day->format('Y-m-d');
		if ($event['sql_where'] && $this->condition) { $event['sql_where'] .= ' AND ' . $this->condition; }
	}
}
