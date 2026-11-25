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
	private $wallet;
	private $activity;

	public function __construct($catalog, $db, $user, $language, $template, $helper, $request, $pagination, $prefix, $wallet, $activity, $names = null)
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
		$this->wallet = $wallet;
		$this->activity = $activity;
	}

	private function header()
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ARC'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_arcade_index')));
	}

	private function start($url, $count, $limit)
	{
		$offset = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), $limit, $count);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $offset);
		return $offset;
	}

	public function index($category = 0, $favourites = false)
	{
		$this->header();
		$reason = $this->catalog->board_access();
		if ($reason === 'LOGIN') { login_box($this->helper->route('phpbb2plus_arcade_index', array(), false)); }
		if ($reason !== '') { return $this->helper->message($reason, array(), 'INFORMATION', $reason === 'PLUS_ARC_CLOSED' ? 200 : 403); }
		if ($favourites && !$this->activity->favourites_enabled()) { throw new NotFoundHttpException(); }
		$id = (int) $category;
		$cats = $this->catalog->categories();
		if ($id && !isset($cats[$id])) { throw new NotFoundHttpException(); }
		foreach ($cats as $key => $cat)
		{
			if ($favourites || (int) $cat['cat_parent'] !== $id) { continue; }
			$link = $cat['cat_type'] === 'l';
			$url = $link ? catalog::plain($cat['cat_desc']) : $this->helper->route('phpbb2plus_arcade_category', array('category' => $key));
			if ($link && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME)), array('http','https'), true))) { continue; }
			$this->template->assign_block_vars('arc_cats', array('TITLE' => catalog::plain($cat['cat_name']), 'DETAIL' => $link ? '' : catalog::plain($cat['cat_desc']),
				'URL' => $link ? utf8_htmlspecialchars($url) : $url));
		}
		$where = $this->catalog->game_filter($id);
		$title = $id ? catalog::plain($cats[$id]['cat_name']) : $this->language->lang('PLUS_ARC');
		$pagelink = $id ? $this->helper->route('phpbb2plus_arcade_category', array('category' => $id)) : $this->helper->route('phpbb2plus_arcade_index');
		if ($favourites)
		{
			$where .= ' AND ' . $this->activity->favourites_filter();
			$title = $this->language->lang('PLUS_ARC_FAVOURITES');
			$pagelink = $this->helper->route('phpbb2plus_arcade_favourites');
		}
		elseif ($this->activity->favourites_enabled())
		{
			$this->template->assign_var('U_ARC_FAVOURITES',$this->helper->route('phpbb2plus_arcade_favourites'));
		}
		if ($favourites || $id || (int) $this->catalog->setting('games_show_all', 1))
		{
			$limit = max(1, min(100, (int) $this->catalog->setting('games_per_page', 25) ?: 25));
			$from = ' FROM ' . $this->prefix . 'games g WHERE ' . $where;
			$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
			$count = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
			$start = $this->start($pagelink, $count, $limit);
			$result = $this->db->sql_query_limit('SELECT g.game_id,g.game_name,g.game_desc,g.played,g.game_avail' . $from . ' ORDER BY g.game_desc,g.game_id', $limit, $start);
			while ($game = $this->db->sql_fetchrow($result))
			{
				$this->template->assign_block_vars('arc_games', array('TITLE' => catalog::plain($game['game_desc'] ?: $game['game_name']),
					'PLAYS' => (int) $game['played'], 'OFFLINE' => !$game['game_avail'], 'URL' => $this->helper->route('phpbb2plus_arcade_game', array('game' => (int) $game['game_id']))));
			}
			$this->db->sql_freeresult($result);
		}
		$this->template->assign_vars(array('ARC_TITLE' => $title, 'ARC_SHOW_PLAYS' => (bool) $this->catalog->setting('games_show_played', 1)));
		return $this->helper->render('@phpbb2plus_arcade/index.html', utf8_htmlspecialchars($title));
	}

	private function player($s)
	{
		if ($s['owner_id'] && $s['username'] !== null) { return get_username_string('full', $s['owner_id'], $s['username'], $s['user_colour']); }
		if ($s['author_removed'] && ($this->names ? $this->names->hidden() : empty($this->user->data['is_registered'])))
		{ return $this->language->lang('PLUS_ARC_FORMER'); }
		return $s['display_name'] !== null && $s['display_name'] !== '' ? utf8_htmlspecialchars(catalog::plain($s['display_name'])) : $this->language->lang('GUEST');
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
		$title = catalog::plain($g['game_desc'] ?: $g['game_name']);
		if ($this->catalog->can_play($g))
		{
			add_form_key('plus_arc_play_' . (int) $g['game_id']);
			$this->template->assign_vars(array('U_ARC_START' => $this->helper->route('phpbb2plus_arcade_start', $params), 'ARC_NONCE' => bin2hex(random_bytes(16))));
		}
		elseif ($g['asset_status'] !== 'ready') { $this->template->assign_var('ARC_NO_FILE', true); }
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
			$result = $this->db->sql_query_limit("SELECT s.owner_id,s.author_removed,s.score_units,$display AS display_name,$date AS score_time,u.username,u.user_colour$fields
				FROM $table s LEFT JOIN " . USERS_TABLE . " u ON u.user_id=s.owner_id AND s.owner_id>0" . $where . ' ORDER BY ' . $sort, 25, $start);
			$position = $start;
			while ($s = $this->db->sql_fetchrow($result))
			{
				$this->template->assign_block_vars('arc_scores', array('POSITION' => ++$position, 'PLAYER' => $this->player($s),
					'POINTS' => score::display($s['score_units'], $this->language->lang('PLUS_ARC_DECIMAL')), 'TIME' => $this->user->format_date((int) $s['score_time']),
					'MONTH' => $history ? sprintf('%04d-%02d', $s['highscore_year'], $s['highscore_mon']) : ''));
			}
			$this->db->sql_freeresult($result);
		}
		$this->template->assign_vars(array('ARC_TITLE' => $title, 'ARC_INSTRUCTIONS' => catalog::plain($g['instructions']),
			'ARC_BOARD' => $board !== '', 'ARC_MONTH' => $board === 'month', 'U_ARC_GAME' => $this->helper->route('phpbb2plus_arcade_game', $params)));
		return $this->helper->render('@phpbb2plus_arcade/game.html', utf8_htmlspecialchars($title));
	}
}
