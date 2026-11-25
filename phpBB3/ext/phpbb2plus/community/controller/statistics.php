<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\statistics_modules;

class statistics
{
	private const COLUMNS = array('most_active_topics' => array('TOPIC','REPLIES'), 'most_viewed_topics' => array('TOPIC','VIEWS'),
		'most_used_languages' => array('PLUS_STATS_LANGUAGE','PLUS_STATS_MEMBERS'), 'top_attachments' => array('PLUS_STATS_FILE','PLUS_STATS_DOWNLOADS'),
		'top_smilies' => array('PLUS_STATS_SMILEY','PLUS_STATS_USES'));
	private const AUTHORS = array('top_posters' => '', 'site_hist_week_top_posters' => 'week', 'site_hist_month_top_posters' => 'month');
	private $db;
	private $auth;
	private $visibility;
	private $user;
	private $config;
	private $text;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $root;
	private $ext;
	private $attachments;
	private $installation;
	private $smilies;

	public function __construct($db, $auth, $visibility, $user, $config, $text, $language, $template, $helper, $request, $root, $ext, $attachments, $installation, $smilies)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->visibility = $visibility;
		$this->user = $user;
		$this->config = $config;
		$this->text = $text;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->root = $root;
		$this->ext = $ext;
		$this->attachments = $attachments;
		$this->installation = $installation;
		$this->smilies = $smilies;
	}

	private function audience($value)
	{
		if ($value === 0) { return true; }
		if (empty($this->user->data['is_registered'])) { return false; }
		return $value === 1 || ($value === 5 && $this->auth->acl_get('a_plus_stats_private'));
	}

	public static function window($period, \DateTimeImmutable $now)
	{
		$start = $now->setTime(0, 0);
		if ($period === 'week') { $start = $start->modify('monday this week'); }
		elseif ($period === 'month') { $start = $start->modify('first day of this month'); }
		else { throw new \InvalidArgumentException('Unknown board statistics period'); }
		return array($start->getTimestamp(), $start->modify('+1 ' . $period)->getTimestamp());
	}

	private function topic_rows($mode, $forums, $scope, $limit)
	{
		$metric = $mode === 'most_active_topics' ? '(GREATEST(t.topic_posts_approved,1)-1)' : 't.topic_views';
		$result = $this->db->sql_query_limit('SELECT t.topic_id,t.topic_title,' . $metric . ' AS amount FROM ' . TOPICS_TABLE . ' t
			JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id
			WHERE ' . $scope . ' AND p.post_visibility=' . ITEM_APPROVED . ' AND (' .
			$this->visibility->get_forums_visibility_sql('post', $forums, 'p.') . ') AND ' . $metric . '>0 ORDER BY amount DESC,t.topic_id', $limit);
		$rows = array();
		while ($r = $this->db->sql_fetchrow($result))
		{
			$rows[] = array('LABEL' => htmlspecialchars_decode(censor_text($r['topic_title']), ENT_QUOTES), 'COUNT' => (int) $r['amount'],
				'URL' => append_sid($this->root . 'viewtopic.' . $this->ext, 't=' . (int) $r['topic_id'], false));
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private function author_rows($period, $forums, $scope, $limit)
	{
		// Count current, published posts in this reader's forums. A user's global
		// profile total can also contain posts from private areas of the board.
		$from = ' FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id WHERE ' . $scope . ' AND p.post_visibility=' . ITEM_APPROVED . ' AND (' .
			$this->visibility->get_forums_visibility_sql('post', $forums, 'p.') . ') AND u.user_id<>' . ANONYMOUS .
			' AND u.user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ')';
		if ($period === '') { $from .= ' AND p.post_postcount=1'; }
		else
		{
			$zone = new \DateTimeZone($this->config['board_timezone'] ?: 'UTC');
			list($start, $end) = self::window($period, new \DateTimeImmutable('now', $zone));
			$from .= ' AND p.post_time>=' . $start . ' AND p.post_time<' . $end;
		}
		$result = $this->db->sql_query_limit('SELECT u.user_id,u.username,COUNT(*) AS amount' . $from .
			' GROUP BY u.user_id,u.username ORDER BY amount DESC,u.user_id', $limit);
		$rows = array();
		while ($r = $this->db->sql_fetchrow($result))
		{
			$rows[] = array('LABEL' => htmlspecialchars_decode($r['username'], ENT_QUOTES), 'COUNT' => (int) $r['amount'],
				'URL' => append_sid($this->root . 'memberlist.' . $this->ext, 'mode=viewprofile&u=' . (int) $r['user_id'], false));
		}
		$this->db->sql_freeresult($result);
		if (!$rows) { return $rows; }
		$total = array_sum(array_column($rows, 'COUNT'));
		if ($period === '')
		{
			$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
			$total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
		}
		foreach ($rows as &$row)
		{
			$row['PERCENT'] = number_format(100 * $row['COUNT'] / max(1, $total), 2, $this->language->lang('PLUS_STATS_DECIMAL'), '');
			$row['BAR'] = $row['COUNT'];
			$row['MAX'] = $rows[0]['COUNT'];
		}
		return $rows;
	}

	private function languages($limit)
	{
		$result = $this->db->sql_query_limit('SELECT u.user_lang,l.lang_local_name,COUNT(*) AS amount FROM ' . USERS_TABLE . ' u
			LEFT JOIN ' . LANG_TABLE . ' l ON l.lang_iso=u.user_lang WHERE u.user_id<>' . ANONYMOUS . ' AND u.user_type IN (' .
			USER_NORMAL . ',' . USER_FOUNDER . ") AND u.user_lang<>'' GROUP BY u.user_lang,l.lang_local_name ORDER BY amount DESC,u.user_lang", $limit);
		$rows = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = array('LABEL' => htmlspecialchars_decode($row['lang_local_name'] ?: $row['user_lang'], ENT_QUOTES), 'COUNT' => (int) $row['amount']);
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function show()
	{
		$this->language->add_lang('statistics', 'phpbb2plus/community');
		$modules = json_decode((string) $this->text->get('plus_statistics_modules'), true);
		$forums = array_diff(array_intersect(array_keys($this->auth->acl_getf('f_list', true)), array_keys($this->auth->acl_getf('f_read', true))),
			$this->user->get_passworded_forums());
		$scope = $forums ? $this->db->sql_in_set('t.forum_id', $forums) . ' AND t.topic_moved_id=0 AND t.topic_visibility=' . ITEM_APPROVED .
			' AND (' . $this->visibility->get_forums_visibility_sql('topic', $forums, 't.') . ')' : '';
		$limit = max(1, min(100, (int) $this->config['plus_statistics_limit']));
		$profiles = $this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel');
		$results = array();
		foreach ((array) $modules as $module)
		{
			$name = trim($module['name']);
			// Earlier conversions recorded the source hash before all native panels existed.
			if ($module['active'] !== 1 || $module['installed'] !== 1 || !in_array($module['renderer'], array('native','unported'), true) ||
				!statistics_modules::recognised($name, $module['source_sha256'] ?? '') || !$this->audience($module['auth_value'])) { continue; }
			$author = isset(self::AUTHORS[$name]);
			if ($author && !$profiles) { continue; }
			$files = $name === 'top_attachments';
			$languages = $name === 'most_used_languages';
			$operations = $name === 'admin_statistics';
			$smilies = $name === 'top_smilies';
			list($label, $metric) = self::COLUMNS[$name] ?? array('USERNAME', 'POSTS');
			$this->template->assign_block_vars('stats_modules', array('ID' => (int) $module['module_id'], 'INDEX' => $name === 'forum_index',
				'FILES' => $files, 'DETAILS' => $operations, 'COLUMN' => $this->language->lang($label),
				'TITLE' => $this->language->lang('PLUS_STATS_' . strtoupper($name)), 'METRIC' => $this->language->lang($metric),
				'SHARE' => $smilies ? $this->language->lang('PLUS_STATS_SHARE_SMILIES') :
					($author ? $this->language->lang($name === 'top_posters' ? 'PLUS_STATS_SHARE_TOTAL' : 'PLUS_STATS_SHARE_RANKED') : '')));
			if ((!$scope && !$languages && !$operations) || $name === 'forum_index') { continue; }
			if (!isset($results[$name]))
			{
				if ($languages) { $results[$name] = $this->languages($limit); }
				elseif ($operations) { $results[$name] = $this->installation->rows($profiles); }
				elseif ($files || $smilies)
				{
					$post_scope = $scope . ' AND p.post_visibility=' . ITEM_APPROVED . ' AND (' . $this->visibility->get_forums_visibility_sql('post', $forums, 'p.') . ')';
					$results[$name] = $files ? $this->attachments->ranking($forums, $post_scope, $limit) : $this->smilies->ranking($post_scope, $limit);
				}
				elseif ($author) { $results[$name] = $this->author_rows(self::AUTHORS[$name], $forums, $scope, $limit); }
				else { $results[$name] = $this->topic_rows($name, $forums, $scope, $limit); }
			}
			foreach ($results[$name] as $row) { $this->template->assign_block_vars('stats_modules.entries', $row); }
		}
		if ($this->request->server('REQUEST_METHOD') === 'GET') { $this->config->increment('plus_statistics_views', 1, false); }
		$started = (int) $this->config['plus_statistics_installed'];
		$this->template->assign_vars(array('STATS_VIEWS' => $this->language->lang('PLUS_STATS_VIEWS', (int) $this->config['plus_statistics_views']),
			'STATS_STARTED' => $started ? $this->language->lang('PLUS_STATS_INSTALLED', $this->user->format_date($started)) : '',
			'STATS_SOURCE' => $this->config['plus_statistics_version'] ? $this->language->lang('PLUS_STATS_VERSION', $this->config['plus_statistics_version']) : ''));
		$response = $this->helper->render('@phpbb2plus_community/statistics.html', $this->language->lang('STATISTICS'));
		$response->setPrivate();
		$response->headers->addCacheControlDirective('no-store');
		return $response;
	}
}
