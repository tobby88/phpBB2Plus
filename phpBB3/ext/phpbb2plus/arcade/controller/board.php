<?php
namespace phpbb2plus\arcade\controller;

use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\score;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class board
{
	private $catalog;
	private $db;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;
	private $prefix;
	private $names;
	private $legacy;
	private $wallet;
	private $activity;
	private $tournaments;
	private $standings;
	private $artwork;
	private $moderation;

	public function __construct($catalog, $db, $user, $language, $template, $helper, $request, $pagination, $prefix, $wallet, $activity, $tournaments, $standings, $artwork, $moderation, $names = null, $legacy = null)
	{
		$this->catalog = $catalog;
		$this->db = $db;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->prefix = $prefix;
		$this->names = $names;
		$this->legacy = $legacy;
		$this->wallet = $wallet;
		$this->activity = $activity;
		$this->tournaments = $tournaments;
		$this->standings = $standings;
		$this->artwork = $artwork;
		$this->moderation = $moderation;
	}

	private function header()
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		$this->template->assign_vars(array('ARC_ICON_WIDTH' => max(1, min(500, (int) $this->catalog->setting('games_image_width', 50))),
			'ARC_ICON_HEIGHT' => max(1, min(500, (int) $this->catalog->setting('games_image_height', 50)))));
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ARC'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_arcade_index')));
	}

	private function start($url, $count, $limit)
	{
		$offset = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), $limit, $count);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $offset);
		return $offset;
	}

	private function directory_filters($sorts, $favourites)
	{
		$term = utf8_substr(trim(catalog::plain($this->request->variable('search_word', '', true))), 0, 255);
		$type = $this->request->variable('search_type', 0);
		$type = in_array($type, array(1, 3), true) ? $type : 0;
		if ($type === 3) { $term = '0-9'; }
		$default_sort = $favourites ? 'alphabetical' : $this->catalog->setting('default_sort', 'date_added');
		$default_order = $favourites ? 'ASC' : strtoupper($this->catalog->setting('default_sort_order', 'DESC'));
		$order = strtoupper($this->request->variable('order', ''));
		if (!in_array($order, array('ASC','DESC'), true)) { $order = $default_order === 'ASC' ? 'ASC' : 'DESC'; }
		if (empty($this->user->data['is_registered']) && $order === 'DESC') { $default_sort = 'allow_guest'; }
		$sort = $this->request->variable('sort_mode', '');
		if (!isset($sorts[$sort])) { $sort = isset($sorts[$default_sort]) ? $default_sort : 'alphabetical'; }
		$filters = array('sort_mode' => $sort, 'order' => $order);
		if ($term !== '') { $filters['search_word'] = $term; }
		if ($type) { $filters['search_type'] = $type; }
		return $filters;
	}

	private function counter($value)
	{
		// Keep the full unsigned historical play counter, including on 32-bit PHP.
		return preg_replace('/\B(?=(?:[0-9]{3})+$)/', $this->language->lang('PLUS_ARC_THOUSANDS'), (string) ($value ?: '0'));
	}

	private function category_list($parent)
	{
		foreach ($this->catalog->category_overview($parent) as $cat)
		{
			$id = (int) $cat['cat_id'];
			$link = $cat['cat_type'] === 'l';
			if ($link && catalog::category_target($cat) === '') { continue; }
			$global = $id === -1;
			$game = !empty($cat['game_id']);
			$this->template->assign_block_vars('arc_cats', array(
				'ID' => $id, 'TITLE' => $global ? $this->language->lang('PLUS_ARC_ALL_GAMES') : catalog::plain($cat['cat_name']),
				'DETAIL' => $link ? '' : catalog::plain($global ? $this->catalog->setting('games_cat_zero', '') : $cat['cat_desc']),
				'URL' => $global ? '#arc-games' : $this->helper->route('phpbb2plus_arcade_category', array('category' => $id)),
				'IS_LINK' => $link, 'GAMES' => $this->counter($cat['total_games']), 'PLAYS' => $this->counter($cat['total_played']),
				'MODERATOR' => !$global && $cat['mod_name'] !== null ? get_username_string('full', $cat['moderator_id'], $cat['mod_name'], $cat['mod_colour']) : '',
				'LAST_GAME' => $game ? catalog::plain($cat['game_desc'] ?: $cat['game_name']) : '',
				'U_LAST_GAME' => $game && ($cat['game_avail'] || $this->catalog->admin()) ? $this->helper->route('phpbb2plus_arcade_game', array('game' => (int) $cat['game_id'])) : '',
				'LAST_PLAYER' => $game ? $this->catalog->last_player($cat, $this->language) : '',
				'LAST_TIME' => $game ? $this->user->format_date((int) $cat['last_time']) : '',
				'ICON' => !$global && !empty($cat['icon_storage']) ? $this->helper->route('phpbb2plus_arcade_category_icon', array('category' => $id)) : '',
			));
		}
		$width = max(0, min(500, (int) $this->catalog->setting('games_cat_image_width', 0)));
		$this->template->assign_vars(array('ARC_CAT_ICON_WIDTH' => $width, 'ARC_CAT_ICON_HEIGHT' => max(1, min(500, (int) $this->catalog->setting('games_cat_image_height', 50)))));
	}

	public function category_icon($category)
	{
		$cats = $this->catalog->categories();
		if ($this->catalog->board_access() !== '' || !isset($cats[(int) $category])) { throw new NotFoundHttpException(); }
		return $this->artwork->response($cats[(int) $category]);
	}

	private function statistics($member, $category)
	{
		$id = (int) ($category['cat_id'] ?? 0);
		$limit = max(1, min(100, (int) $this->catalog->setting('games_total_top', 10)));
		$popular = $this->catalog->chart_games('popular', $limit, $id);
		if (!$popular) { return; }
		$second = $this->catalog->setting('games_new_games', 1) ? 'recent' : 'least';
		$show_second = !$id || $second === 'recent' || $this->catalog->enough_for_least_played($id, (int) $category['special_play']);
		foreach (array('popular' => $popular, $second => $show_second ? $this->catalog->chart_games($second, $limit, $id) : array()) as $chart => $games)
		{
			$this->template->assign_block_vars('arc_charts', array('KEY' => $chart,
				'TITLE' => $this->language->lang('PLUS_ARC_CHART_' . strtoupper($chart))));
			foreach ($games as $game)
			{
				$this->template->assign_block_vars('arc_charts.games', array('TITLE' => catalog::plain($game['game_desc'] ?: $game['game_name']),
					'URL' => $game['allow_guest'] || !empty($this->user->data['is_registered'])
						? $this->helper->route('phpbb2plus_arcade_game', array('game' => (int) $game['game_id'])) : ''));
			}
		}
		if ($id) { return; }
		$periods = $this->catalog->setting('games_at_highscore', 1) ? array('current','all') : array('current');
		foreach ($periods as $period)
		{
			$all_time = $period === 'all';
			$leader = $this->standings->leading_player($all_time);
			$this->template->assign_block_vars('arc_records', array('KEY' => $period,
				'TITLE' => $this->language->lang($all_time ? 'PLUS_ARC_ALL' : 'PLUS_ARC_CURRENT'),
				'PLAYER' => $leader ? get_username_string('full', $leader['user_id'], $leader['username'], $leader['user_colour']) : '',
				'WINS' => $leader ? (int) $leader['wins'] : 0));
			if (empty($this->user->data['is_registered']) || !$this->catalog->can_view_awards($member)) { continue; }
			$counts = $this->catalog->award_counts($this->catalog->award_query($member, $all_time));
			for ($place = 1; $place <= 3; $place++)
			{
				$this->template->assign_block_vars('arc_records.places', array('PLACE' => $place, 'COUNT' => (int) ($counts[$place] ?? 0),
					'URL' => $this->helper->route('phpbb2plus_arcade_awards', array('member' => $member, 'period' => $period, 'place' => $place))));
			}
		}
	}

	public function index($category = 0, $favourites = false)
	{
		$this->header();
		$id = (int) $category;
		$route = $favourites ? 'phpbb2plus_arcade_favourites' : ($id ? 'phpbb2plus_arcade_category' : 'phpbb2plus_arcade_index');
		$params = $id ? array('category' => $id) : array();
		$sorts = $this->catalog->directory_sorts($this->activity->rating_scale());
		$filters = $this->directory_filters($sorts, $favourites);
		$term = $filters['search_word'] ?? '';
		$type = $filters['search_type'] ?? 0;
		$reason = $this->catalog->board_access();
		if ($reason === 'LOGIN') { login_box($this->helper->route($route, $params + $filters, false)); }
		if ($reason !== '') { return $this->helper->message($reason, array(), 'INFORMATION', $reason === 'PLUS_ARC_CLOSED' ? 200 : 403); }
		if ($favourites && !$this->activity->favourites_enabled()) { throw new NotFoundHttpException(); }
		$cats = $this->catalog->categories();
		if ($id && !isset($cats[$id])) { throw new NotFoundHttpException(); }
		if ($id && $cats[$id]['cat_type'] === 'l')
		{
			$url = $this->catalog->visit_link($cats[$id], $this->request->server('REQUEST_METHOD', 'GET') === 'GET');
			return new RedirectResponse($url, 302, array('Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'));
		}
		if (!$favourites) { $this->category_list($id); }
		$where = $this->catalog->game_filter($id) . $this->catalog->title_filter($term, $type);
		$member = (int) $this->user->data['user_id'];
		if (!$favourites && $term === '' && !$this->request->variable('start', 0) && (int) $this->catalog->setting('games_show_stats', 1))
		{ $this->statistics($member, $cats[$id] ?? null); }
		$moderated = $this->moderation->categories();
		if ($moderated && (!$id || isset($moderated[$id])))
		{
			$this->template->assign_var('U_ARC_MOD', $this->helper->route('phpbb2plus_arcade_moderate', $id ? array('category' => $id) : array()));
		}
		if ((int) $this->catalog->setting('games_show_mhm', 1))
		{
			$this->template->assign_var('U_ARC_MONTHLY', $this->helper->route('phpbb2plus_arcade_monthly'));
		}
		if (!empty($this->user->data['is_registered']))
		{
			$this->template->assign_var('U_ARC_POINTS', $this->helper->route('phpbb2plus_arcade_points'));
		}
		if ($this->tournaments->available())
		{
			$this->template->assign_var('U_ARC_TOURNAMENTS', $this->helper->route('phpbb2plus_arcade_tournaments'));
		}
		if (!empty($this->user->data['is_registered']) && $this->catalog->can_view_awards($member))
		{
			$this->template->assign_var('U_ARC_MY_AWARDS',$this->helper->route('phpbb2plus_arcade_awards',array('member'=>$member,'period'=>'current')));
		}
		$title = $id ? catalog::plain($cats[$id]['cat_name']) : $this->language->lang('PLUS_ARC');
		if ($favourites)
		{
			$where .= ' AND ' . $this->activity->favourites_filter();
			$title = $this->language->lang('PLUS_ARC_FAVOURITES');
		}
		elseif ($this->activity->favourites_enabled())
		{
			$this->template->assign_var('U_ARC_FAVOURITES',$this->helper->route('phpbb2plus_arcade_favourites'));
		}
		foreach (array_keys($sorts) as $key)
		{
			$this->template->assign_block_vars('arc_sorts', array('KEY' => $key, 'SELECTED' => $key === $filters['sort_mode'],
				'LABEL' => $this->language->lang('PLUS_ARC_SORT_' . strtoupper($key))));
		}
		$this->template->assign_vars(array('U_ARC_FILTER' => $this->helper->route($route, $params), 'ARC_SEARCH' => $term,
			'ARC_SEARCH_TYPE' => $type, 'ARC_FILTERED' => $term !== '', 'ARC_ASC' => $filters['order'] === 'ASC'));
		if ($term !== '' || $favourites || $id || (int) $this->catalog->setting('games_show_all', 1))
		{
			$limit = max(1, min(100, (int) $this->catalog->setting('games_per_page', 25) ?: 25));
			$from = ' FROM ' . $this->prefix . 'games g WHERE ' . $where;
			$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
			$count = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
			$start = $this->start($this->helper->route($route, $params + $filters), $count, $limit);
			$result = $this->db->sql_query_limit('SELECT g.game_id,g.game_name,g.game_desc,g.played,g.game_avail,g.icon_storage' .
				$from . ' ORDER BY ' . $sorts[$filters['sort_mode']] . ' ' . $filters['order'] . ',g.game_id', $limit, $start);
			while ($game = $this->db->sql_fetchrow($result))
			{
				$this->template->assign_block_vars('arc_games', array('TITLE' => catalog::plain($game['game_desc'] ?: $game['game_name']),
					'ICON' => $this->icon_url($game),
					'PLAYS' => (int) $game['played'], 'OFFLINE' => !$game['game_avail'], 'URL' => $this->helper->route('phpbb2plus_arcade_game', array('game' => (int) $game['game_id']))));
			}
			$this->db->sql_freeresult($result);
		}
		$this->template->assign_vars(array('ARC_TITLE' => $title, 'ARC_SHOW_PLAYS' => (bool) $this->catalog->setting('games_show_played', 1)));
		return $this->helper->render('@phpbb2plus_arcade/index.html', utf8_htmlspecialchars($title));
	}

	private function player($s)
	{
		return catalog::player_name($s,$this->language,$this->names ? $this->names->hidden() : empty($this->user->data['is_registered']));
	}

	private function icon_url($game)
	{
		return $game['icon_storage'] === '' ? '' : $this->helper->route('phpbb2plus_arcade_icon', array('game' => (int) $game['game_id']));
	}

	public function icon($game)
	{
		return $this->artwork->response($this->catalog->game((int) $game));
	}

	public function legacy_rating($game)
	{
		$params = array('game' => (int) $game);
		if (empty($this->user->data['is_registered'])) { login_box($this->helper->route('phpbb2plus_arcade_legacy_rating', $params, false)); }
		if (!$this->activity->may_vote($this->catalog->game($game))) { throw new NotFoundHttpException(); }
		return new RedirectResponse($this->helper->route('phpbb2plus_arcade_game', $params, false) . '#arcade-rating', 302,
			array('Cache-Control' => 'private, no-store'));
	}

	private function month_label($month)
	{
		// Format in the middle of the board month so visitor time zones cannot change its label.
		return $this->user->format_date($month->modify('+14 days')->getTimestamp(), 'F Y', true);
	}

	public function standings($monthly)
	{
		$this->header();
		$guest = empty($this->user->data['is_registered']);
		$route = $monthly ? 'phpbb2plus_arcade_monthly' : 'phpbb2plus_arcade_points';
		$reason = $this->catalog->board_access();
		$date = $this->standings->month($monthly ? $this->request->variable('month', '') : '', $monthly ? $this->request->variable('date', 0) : 0);
		$period = $date->format('Y-m');
		if ($reason === 'LOGIN' || ($reason === '' && !$monthly && $guest))
		{
			login_box($this->helper->route($route, $monthly ? array('month' => $period) : array(), false));
		}
		if ($reason !== '') { return $this->helper->message($reason, array(), 'INFORMATION', $reason === 'PLUS_ARC_CLOSED' ? 200 : 403); }
		$title = $this->language->lang($monthly ? 'PLUS_ARC_MONTH' : 'PLUS_ARC_POINT_STANDINGS');
		$url = $this->helper->route($route);
		if ($monthly)
		{
			if (!(int) $this->catalog->setting('games_show_mhm', 1)) { throw new NotFoundHttpException(); }
			$months = array_unique(array_merge($this->standings->available_months($guest), array($period)));
			rsort($months, SORT_STRING);
			foreach ($months as $key)
			{
				$this->template->assign_block_vars('arc_months', array('KEY' => $key, 'LABEL' => $this->month_label($this->standings->month($key)), 'ACTIVE' => $key === $period));
			}
			foreach ($this->standings->monthly($date, $guest) as $row)
			{
				$this->template->assign_block_vars('arc_champions', array(
					'TITLE' => catalog::plain($row['game_desc'] ?: $row['game_name']), 'PLAYER' => $this->player($row),
					'ICON' => $this->icon_url($row),
					'SCORE' => score::display($row['score_units'], $this->language->lang('PLUS_ARC_DECIMAL')),
					'URL' => $this->helper->route('phpbb2plus_arcade_game', array('game' => (int) $row['game_id'])),
				));
			}
			$this->template->assign_vars(array('ARC_MONTH_LABEL' => $this->month_label($date),
				'U_ARC_PREVIOUS' => $period > '1000-01' ? $this->helper->route($route, array('month' => $date->modify('-1 month')->format('Y-m'))) : '',
				'U_ARC_NEXT' => $period < '9999-12' ? $this->helper->route($route, array('month' => $date->modify('+1 month')->format('Y-m'))) : ''));
		}
		else
		{
			$players = $this->standings->points($date);
			$start = $this->start($url, count($players), 25);
			foreach (array_slice($players, $start, 25) as $row)
			{
				$this->template->assign_block_vars('arc_totals', array('RANK' => ++$start, 'PLAYER' => $this->player($row),
					'MONTHLY' => $row['monthly'], 'ALL_TIME' => $row['all_time'], 'POINTS' => $row['points']));
			}
		}
		$this->template->assign_vars(array('ARC_TITLE' => $title, 'ARC_MONTHLY' => $monthly, 'U_ARC_STANDINGS' => $url));
		return $this->helper->render('@phpbb2plus_arcade/standings.html', $title);
	}

	public function feedback($game,$operation)
	{
		$this->header();
		$id = (int) $game;
		if (!check_form_key('plus_arc_activity_' . $id)) { throw new AccessDeniedHttpException(); }
		$value = $this->request->variable('value',-1,false,\phpbb\request\request_interface::POST);
		try { $this->activity->write($id,$operation,$value); }
		catch (\DomainException $e) { return $this->helper->message($e->getMessage(),array(),'INFORMATION',422); }
		return new RedirectResponse($this->helper->route('phpbb2plus_arcade_game',array('game'=>$id),false),303);
	}

	public function awards($member,$period,$legacy = false)
	{
		$this->header();
		$id = (int) $member;
		$reason = $this->catalog->board_access();
		if ($reason === 'LOGIN' || ($legacy && !$id && empty($this->user->data['is_registered'])))
		{
			$route = $legacy ? 'phpbb2plus_arcade_legacy_awards' : 'phpbb2plus_arcade_awards';
			login_box($this->helper->route($route,array('member'=>$id,'period'=>$period,'place'=>$this->request->variable('place',0)),false));
		}
		if ($reason !== '') { throw new NotFoundHttpException(); }
		if ($legacy) { $id = $id ? ($this->legacy ? $this->legacy->member_id($id) : 0) : (int) $this->user->data['user_id']; }
		if (!$this->catalog->can_view_awards($id)) { throw new NotFoundHttpException(); }
		$params = array('member'=>$id,'period'=>$period);
		$all_time = (int) $this->catalog->setting('games_at_highscore',1);
		$place = $this->request->variable('place',0);
		if (($period === 'all' && !$all_time) || $place < 0 || $place > 3) { throw new NotFoundHttpException(); }
		$r = $this->db->sql_query('SELECT username FROM ' . USERS_TABLE . " WHERE user_id=$id AND user_type IN (" . USER_NORMAL . ',' . USER_FOUNDER . ')');
		$name = $this->db->sql_fetchfield('username'); $this->db->sql_freeresult($r);
		if ($name === false) { throw new NotFoundHttpException(); }
		$source = $this->catalog->award_query($id,$period === 'all');
		$counts = $this->catalog->award_counts($source);
		$total = $place ? (int) ($counts[$place] ?? 0) : array_sum($counts);
		$url = $this->helper->route('phpbb2plus_arcade_awards',$params + ($place ? array('place'=>$place) : array()));
		$start = $this->start($url,$total,25);
		for ($i=1; $i<=3; $i++)
		{
			$this->template->assign_block_vars('arc_places',array('PLACE'=>$i,'COUNT'=>(int) ($counts[$i] ?? 0),'ACTIVE'=>$i===$place,
				'URL'=>$this->helper->route('phpbb2plus_arcade_awards',$params + array('place'=>$i))));
		}
		if ($total)
		{
			$filter = $place ? "place=$place" : 'place>0';
			$r = $this->db->sql_query_limit("SELECT * FROM $source WHERE $filter ORDER BY place,game_desc,game_id,row_id",25,$start);
			while ($row = $this->db->sql_fetchrow($r))
			{
				$this->template->assign_block_vars('arc_medals',array('PLACE'=>(int) $row['place'],'GAME'=>catalog::plain($row['game_desc'] ?: $row['game_name']),
					'URL'=>$this->helper->route('phpbb2plus_arcade_scores',array('game'=>(int) $row['game_id'],'board'=>$period)),
					'POINTS'=>score::display($row['score_units'],$this->language->lang('PLUS_ARC_DECIMAL')),
					'DATE'=>$this->user->format_date((int) $row['recorded_at']),'SECONDS'=>(int) $row['time_taken']));
			}
			$this->db->sql_freeresult($r);
		}
		$title = $this->language->lang('PLUS_ARC_AWARDS_FOR',htmlspecialchars_decode($name,ENT_QUOTES));
		$this->template->assign_vars(array('ARC_TITLE'=>$title,'ARC_ALL_TIME'=>$period==='all','ARC_FILTERED'=>(bool) $place,
			'U_ARC_CURRENT'=>$this->helper->route('phpbb2plus_arcade_awards',array('member'=>$id,'period'=>'current')),
			'U_ARC_ALL'=>$all_time ? $this->helper->route('phpbb2plus_arcade_awards',array('member'=>$id,'period'=>'all')) : ''));
		return $this->helper->render('@phpbb2plus_arcade/awards.html',utf8_htmlspecialchars($title));
	}

	private function activity_forms($game)
	{
		$id = (int) $game['game_id'];
		$params = array('game'=>$id);
		if ($this->activity->favourites_enabled())
		{
			$this->template->assign_vars(array('U_ARC_FAVOURITES'=>$this->helper->route('phpbb2plus_arcade_favourites'),
				'U_ARC_FAVOURITE'=>$this->helper->route('phpbb2plus_arcade_feedback',$params + array('operation'=>'favourite')),
				'ARC_FAVOURITE'=>$this->activity->favourite($id)));
		}
		$scale = $this->activity->rating_scale();
		if ($scale)
		{
			$votes = $this->activity->votes($id);
			$this->template->assign_vars(array('ARC_RATINGS'=>true,'ARC_VOTES'=>(int) $votes['total'],'ARC_RATING_SCALE'=>$scale,
				'ARC_MEAN'=>number_format((float) $votes['mean'],1,$this->language->lang('PLUS_ARC_DECIMAL'),'')));
			if (!$votes['voted'] && $this->activity->may_vote($game))
			{
				$this->template->assign_var('U_ARC_RATE',$this->helper->route('phpbb2plus_arcade_feedback',$params + array('operation'=>'rate')));
			}
		}
		if (!empty($this->user->data['is_registered']))
		{
			add_form_key('plus_arc_activity_' . $id);
			$this->template->assign_var('ARC_ACTIVITY_TOKEN',$this->template->retrieve_var('S_FORM_TOKEN'));
		}
	}

	public function game($game, $board = '')
	{
		$this->header();
		$g = $this->catalog->game($game);
		$this->activity_forms($g);
		$account = $this->wallet->account((int) $this->user->data['user_id']);
		if ($account && $account['balance_text'] !== '')
		{
			$this->template->assign_vars(array('ARC_WALLET' => true, 'ARC_BALANCE' => $account['balance_text'] ?? $account['initial_amount'],
				'ARC_CURRENCY' => catalog::plain($account['currency_name']) ?: $this->language->lang('PLUS_ARC_POINTS'), 'ARC_COST' => (int) $g['game_charge']));
		}
		$params = array('game' => (int) $g['game_id']);
		$category = (int) $g['cat_id'];
		if (isset($this->moderation->categories()[$category]))
		{
			$this->template->assign_var('U_ARC_MOD', $this->helper->route('phpbb2plus_arcade_moderate',
				$params + array('category' => $category, 'board' => $board === 'all' ? 'all' : 'current')));
		}
		if ($this->catalog->comment_limit($g)) { $this->template->assign_var('U_ARC_COMMENTS',$this->helper->route('phpbb2plus_arcade_comments',$params)); }
		$title = catalog::plain($g['game_desc'] ?: $g['game_name']);
		if ($this->catalog->can_play($g))
		{
			add_form_key('plus_arc_play_' . (int) $g['game_id']);
			$this->template->assign_vars(array('U_ARC_START' => $this->helper->route('phpbb2plus_arcade_start', $params), 'ARC_NONCE' => bin2hex(random_bytes(16))));
		}
		elseif ($g['asset_status'] !== 'ready') { $this->template->assign_var('ARC_NO_FILE', true); }
		elseif ($this->catalog->can_play($g, true)) { $this->template->assign_var('ARC_TOUR_RESERVED', true); }
		$scoreboards = $this->catalog->scores_available($g);
		$all = $scoreboards && (int) $this->catalog->setting('games_at_highscore', 1) && (int) $g['at_highscore_limit'] > 0;
		$monthly = $scoreboards && (int) $this->catalog->setting('games_show_mhm', 1);
		if ($board !== '' && (!$scoreboards || ($board === 'all' && !$all) || ($board === 'month' && !$monthly))) { throw new NotFoundHttpException(); }
		if ($scoreboards)
		{
			$same = !$all || $this->catalog->same_boards($g);
			$links = array('current' => $same ? 'PLUS_ARC_BOARD' : 'PLUS_ARC_CURRENT');
			if (!$same) { $links['all'] = 'PLUS_ARC_ALL'; }
			if ($monthly) { $links['month'] = 'PLUS_ARC_MONTH'; }
			foreach ($links as $key => $label)
			{
				$this->template->assign_block_vars('arc_boards', array('LABEL' => $this->language->lang($label), 'URL' => $this->helper->route('phpbb2plus_arcade_scores', $params + array('board' => $key))));
			}
		}
		if ($board !== '')
		{
			$history = $board === 'month';
			$table = $this->prefix . ($history ? 'monthly' : ($board === 'all' ? 'all_scores' : 'scores'));
			$name = $history ? 'highscore_game' : 'game_name';
			$where = " WHERE s.$name='" . $this->db->sql_escape($g['game_name']) . "'";
			$result = $this->db->sql_query("SELECT COUNT(*) AS total FROM $table s" . $where);
			$count = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
			$start = $this->start($this->helper->route('phpbb2plus_arcade_scores', $params + array('board' => $board)), $count, 25);
			$date = $history ? 's.highscore_date' : 's.recorded_at';
			$display = $history ? 's.highscore_player' : ($board === 'all' ? "COALESCE(NULLIF(s.player_name,''),s.player)" : 's.player');
			$sort = $history ? 's.highscore_year DESC,s.highscore_mon DESC,s.highscore_id' :
				's.score_units ' . ($g['reverse_list'] ? 'ASC' : 'DESC') . ',s.recorded_at,s.player_id,s.row_id';
			$fields = $history ? ',s.highscore_year,s.highscore_mon' : '';
			$result = $this->db->sql_query_limit("SELECT s.owner_id,s.author_removed,s.score_units,$display AS display_name,$date AS score_time,u.username,u.user_colour,u.user_type$fields
				FROM $table s LEFT JOIN " . USERS_TABLE . " u ON u.user_id=s.owner_id AND s.owner_id>0" . $where . ' ORDER BY ' . $sort, 25, $start);
			$position = $start;
			while ($s = $this->db->sql_fetchrow($result))
			{
				$this->template->assign_block_vars('arc_scores', array('POSITION' => ++$position, 'PLAYER' => $this->player($s),
					'U_AWARDS' => $s['username'] !== null && in_array((int) $s['user_type'],array(USER_NORMAL,USER_FOUNDER),true) && $this->catalog->can_view_awards($s['owner_id']) ?
						$this->helper->route('phpbb2plus_arcade_awards',array('member'=>(int) $s['owner_id'],'period'=>$board === 'all' ? 'all' : 'current')) : '',
					'POINTS' => score::display($s['score_units'], $this->language->lang('PLUS_ARC_DECIMAL')), 'TIME' => $this->user->format_date((int) $s['score_time']),
					'MONTH' => $history ? sprintf('%04d-%02d', $s['highscore_year'], $s['highscore_mon']) : ''));
			}
			$this->db->sql_freeresult($result);
		}
		$this->template->assign_vars(array('ARC_TITLE' => $title, 'ARC_INSTRUCTIONS' => catalog::plain($g['instructions']), 'U_ARC_ICON' => $this->icon_url($g),
			'ARC_CONTROLS' => in_array((int) $g['game_control'],array(1,2,3),true) ? $this->language->lang('PLUS_ARC_CONTROL_' . (int) $g['game_control']) : '',
			'ARC_BOARD' => $board !== '', 'ARC_MONTH' => $board === 'month', 'U_ARC_GAME' => $this->helper->route('phpbb2plus_arcade_game', $params)));
		return $this->helper->render('@phpbb2plus_arcade/game.html', utf8_htmlspecialchars($title));
	}
}
