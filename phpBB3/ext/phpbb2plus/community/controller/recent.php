<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\topic_activity;

class recent
{
	private const MODES = array('today'=>'PLUS_RECENT_TODAY', 'yesterday'=>'PLUS_RECENT_YESTERDAY',
		'last24'=>'PLUS_RECENT_DAY', 'lastweek'=>'PLUS_RECENT_WEEK', 'lastXdays'=>'PLUS_RECENT_DAYS');
	private const PAGE_SIZE = 10;
	private $activity;
	private $request;
	private $config;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $pagination;
	private $root;
	private $ext;

	public function __construct($activity, $request, $config, $user, $language, $template, $helper, $pagination, $root, $ext)
	{
		$this->activity = $activity;
		$this->request = $request;
		$this->config = $config;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->pagination = $pagination;
		$this->root = $root;
		$this->ext = $ext;
	}

	public function view()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$this->language->add_lang('viewtopic');
		$mode = $this->request->variable('mode', 'today');
		if (!isset(self::MODES[$mode])) { $mode = 'today'; }
		$days = max(1, min(365, $this->request->variable('amount_days', 3)));
		$period = topic_activity::period($mode, $days, time(), new \DateTimeZone($this->config['board_timezone']));
		$page = $this->activity->page($this->activity->forums(), self::PAGE_SIZE, $period, $this->request->variable('start', 0));
		$route = 'phpbb2plus_community_recent';
		$params = array('mode'=>$mode, 'amount_days'=>$days);
		$url = $this->helper->route($route, $params);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $page['total'], self::PAGE_SIZE, $page['start']);
		$this->template->assign_vars(array('U_PLUS_RECENT'=>$this->helper->route($route), 'ACTIVITY_DAYS'=>$days,
			'ACTIVITY_COUNT'=>$this->language->lang('PLUS_RECENT_COUNT', $page['total'])));
		foreach (self::MODES as $key=>$label)
		{
			$this->template->assign_block_vars('periods', array('VALUE'=>$key, 'LABEL'=>$this->language->lang($label), 'SELECTED'=>$mode === $key));
		}
		$tracked = array();
		if ($this->user->data['is_registered'])
		{
			$by_forum = array();
			foreach ($page['rows'] as $r) { $by_forum[$r['forum_id']][$r['topic_id']] = $r; }
			foreach ($by_forum as $forum=>$topics)
			{
				$ids = array_keys($topics);
				if ($this->config['load_db_lastread'])
				{
					$last = reset($topics);
					$tracked += get_topic_tracking((int) $forum, $ids, $topics, array($forum=>$last['forum_mark_time']));
				}
				else { $tracked += get_complete_topic_tracking((int) $forum, $ids); }
			}
		}
		include_once $this->root . 'includes/functions_display.' . $this->ext;
		foreach ($page['rows'] as $r)
		{
			$topic = (int) $r['topic_id'];
			$post = (int) $r['post_id'];
			$unread = isset($tracked[$topic]) && (int) $r['post_time'] > $tracked[$topic];
			$replies = max(0, (int) $r['topic_posts_approved'] - 1);
			topic_status($r, $replies, $unread, $icon, $alt, $type);
			$topic_url = append_sid($this->root . 'viewtopic.' . $this->ext, 't=' . $topic);
			$this->template->assign_block_vars('activity', array(
				'ID'=>$topic, 'TITLE'=>censor_text($r['topic_title']), 'URL'=>$topic_url, 'FORUM'=>$r['forum_name'],
				'FORUM_URL'=>append_sid($this->root . 'viewforum.' . $this->ext, 'f=' . (int) $r['forum_id']),
				'LAST_URL'=>append_sid($this->root . 'viewtopic.' . $this->ext, 'p=' . $post) . '#p' . $post,
				'FIRST_AUTHOR'=>$r['first_author'] ?? $this->language->lang('PLUS_REMOVED_POSTS', 1),
				'FIRST_TIME'=>isset($r['first_author']) ? $this->user->format_date((int) $r['first_time']) : '',
				'LAST_AUTHOR'=>$r['last_author'], 'LAST_TIME'=>$this->user->format_date((int) $r['post_time']),
				'REPLIES'=>$replies, 'VIEWS'=>(int) $r['topic_views'], 'TYPE'=>$type, 'ICON'=>$icon, 'ALT'=>$this->language->lang($alt),
				'UNREAD'=>$unread ? append_sid($this->root . 'viewtopic.' . $this->ext, 't=' . $topic . '&amp;view=unread') . '#unread' : '',
			));
			$this->pagination->generate_template_pagination($topic_url, 'activity.pagination', 'start', $replies + 1, max(1, (int) $this->config['posts_per_page']), 1, true, true);
		}
		$response = $this->helper->render('@phpbb2plus_community/recent.html', $this->language->lang('PLUS_RECENT_TOPICS'));
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}
}
