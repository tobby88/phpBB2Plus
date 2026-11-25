<?php
namespace phpbb2plus\arcade\controller;

use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\score;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class tournaments
{
	private $tours;
	private $sessions;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;
	private $management;

	public function __construct($tours, $sessions, $user, $language, $template, $helper, $request, $pagination, $management)
	{
		$this->tours = $tours;
		$this->sessions = $sessions;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->management = $management;
	}

	private function header($route, $params = array())
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		if (empty($this->user->data['is_registered']))
		{
			login_box($this->helper->route($route, $params, false));
		}
		if (!$this->tours->available()) { throw new NotFoundHttpException(); }
		foreach (array('phpbb2plus_arcade_index'=>'PLUS_ARC', 'phpbb2plus_arcade_tournaments'=>'PLUS_ARC_TOURS') as $link=>$label)
		{
			$this->template->assign_block_vars('navlinks', array('FORUM_NAME'=>$this->language->lang($label), 'U_VIEW_FORUM'=>$this->helper->route($link)));
		}
	}

	private function start($route, $params, $total)
	{
		$start = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), 25, $total);
		$this->pagination->generate_template_pagination($this->helper->route($route, $params), 'pagination', 'start', $total, 25, $start);
		return $start;
	}

	private function player($row)
	{
		// Tournaments only stored the old account ID. A missing positive ID is a former member.
		return catalog::player_name($row + array('display_name'=>'', 'author_removed'=>(int) $row['source_player'] > 0), $this->language, true);
	}

	private function display($entry)
	{
		$id = (int) $entry['tour_id'];
		$status = $this->tours->join_status($entry);
		$states = \phpbb2plus\arcade\service\tournaments::STATES;
		return array('ID'=>$id, 'NAME'=>(string) $entry['tour_name'], 'DESCRIPTION'=>(string) $entry['tour_desc'],
			'PLAYERS'=>(int) $entry['players'], 'CAPACITY'=>(int) $entry['tour_max_players'], 'INVITED'=>(bool) $entry['invited'],
			'STATE'=>$this->language->lang($states[(int) $entry['tour_active']] ?? $states[0]), 'CAN_JOIN'=>$status === '',
			'STATUS'=>$status !== '' && $status !== 'PLUS_ARC_TOUR_CLOSED' ? $this->language->lang($status) : '',
			'URL'=>$this->helper->route('phpbb2plus_arcade_tournament', array('id'=>$id)));
	}

	public function listing($champions = false)
	{
		$route = $champions ? 'phpbb2plus_arcade_champions' : 'phpbb2plus_arcade_tournaments';
		$this->header($route);
		$start = $this->start($route, array(), $this->tours->total($champions));
		$can_join = false;
		if (!$champions)
		{
			if ($this->management->proposals_enabled() && $this->management->room())
			{
				$this->template->assign_var('U_PROPOSE', $this->helper->route('phpbb2plus_arcade_propose'));
			}
			foreach ($this->tours->listing($start, 25) as $row)
			{
				$entry = $this->display($row);
				$this->template->assign_block_vars('tours', $entry);
				$can_join = $can_join || $entry['CAN_JOIN'];
			}
		}
		foreach ($this->tours->champions($champions ? $start : 0, $champions ? 25 : 5) as $row)
		{
			$this->template->assign_block_vars('champions', array('NAME'=>(string) $row['tour_name'], 'PLAYER'=>$this->player($row),
				'URL'=>$this->helper->route('phpbb2plus_arcade_tournament', array('id'=>(int) $row['tour_id']))));
		}
		if ($can_join)
		{
			add_form_key('plus_arc_tour_join');
			$this->template->assign_var('U_JOIN', $this->helper->route('phpbb2plus_arcade_join_tournaments'));
		}
		$title = $this->language->lang($champions ? 'PLUS_ARC_TOUR_CHAMPIONS' : 'PLUS_ARC_TOURS');
		$this->template->assign_vars(array('ARC_TITLE'=>$title, 'CHAMPIONS_ONLY'=>(bool) $champions,
			'U_CHAMPIONS'=>$this->helper->route('phpbb2plus_arcade_champions')));
		return $this->helper->render('@phpbb2plus_arcade/tournaments.html', $title);
	}

	public function proposal()
	{
		$this->header('phpbb2plus_arcade_propose');
		if (!$this->management->proposals_enabled()) { throw new NotFoundHttpException(); }
		$max = $this->management->limit('games_tournament_players');
		$input = array('name'=>'', 'description'=>'', 'players'=>2, 'turns'=>1, 'reserve'=>'0');
		$nonce = bin2hex(random_bytes(16));
		$error = '';
		if ($this->request->is_set_post('propose'))
		{
			if (!check_form_key('plus_arc_proposal')) { throw new AccessDeniedHttpException(); }
			foreach (array_keys($input) as $key)
			{
				$value = $this->request->variable($key, $key === 'reserve' ? '0' : '', true, \phpbb\request\request_interface::POST);
				$input[$key] = htmlspecialchars_decode($value, ENT_QUOTES);
			}
			$nonce = $this->request->variable('nonce', '', false, \phpbb\request\request_interface::POST);
			try
			{
				$id = $this->sessions->locked(function () use ($input, $nonce) { return $this->management->propose($input, $nonce); });
				return new RedirectResponse($this->helper->route('phpbb2plus_arcade_tournament', array('id'=>$id), false), 303);
			}
			catch (\DomainException $e) { $error = $this->language->lang($e->getMessage()); }
		}
		elseif (!$this->management->room()) { return $this->helper->message('PLUS_ARC_PROPOSAL_LIMIT', array(), 'INFORMATION', 409); }
		add_form_key('plus_arc_proposal');
		$title = $this->language->lang('PLUS_ARC_PROPOSE');
		$this->template->assign_vars(array('ARC_TITLE'=>$title, 'ARC_ERROR'=>$error, 'MAX_PLAYERS'=>$max, 'PROPOSAL'=>$input,
			'NONCE'=>$nonce, 'U_PROPOSE'=>$this->helper->route('phpbb2plus_arcade_propose')));
		return $this->helper->render('@phpbb2plus_arcade/proposal.html', $title, $error === '' ? 200 : 422);
	}

	public function detail($id)
	{
		$params = array('id'=>(int) $id);
		$this->header('phpbb2plus_arcade_tournament', $params);
		$entry = $this->tours->entry($id);
		$view = $this->display($entry);
		$start = $this->start('phpbb2plus_arcade_tournament', $params, $view['PLAYERS']);
		$participants = $this->tours->participants($id, $start, 25);
		$history = $this->tours->participant_games($participants);
		foreach ($participants as $row)
		{
			$this->template->assign_block_vars('tour_players', array('NAME'=>$this->player($row)));
			foreach ($history[$row['row_id']] ?? array() as $played)
			{
				$this->template->assign_block_vars('tour_players.plays', array('NAME'=>$played['name'], 'SHOW_COUNT'=>$played['played'] !== null,
					'COUNT'=>$played['played'], 'SHOW_SCORE'=>$played['score'] !== null,
					'SCORE'=>$played['score'] === null ? '' : score::display($played['score'], $this->language->lang('PLUS_ARC_DECIMAL'))));
			}
		}
		$has_start = false;
		foreach ($this->tours->games($id) as $game)
		{
			$recorded = $game['score_units'] !== null;
			$can_start = $this->tours->can_start($entry, $game);
			$has_start = $has_start || $can_start;
			$game_params = array('game'=>(int) $game['game_id']);
			$this->template->assign_block_vars('tour_games', array('ID'=>(int) $game['game_id'], 'NAME'=>catalog::plain($game['game_desc'] ?: $game['game_name']),
				'URL'=>$this->helper->route('phpbb2plus_arcade_game', $game_params),
				'START'=>$can_start ? $this->helper->route('phpbb2plus_arcade_tournament_start', $game_params + array('tour'=>(int) $id)) : '',
				'NONCE'=>$can_start ? bin2hex(random_bytes(16)) : '', 'MEMBER'=>(bool) $entry['is_member'],
				'READABLE'=>(int) $game['turns_state'] === 1, 'REMAINING'=>max(0, (int) $entry['tour_player_turns'] - (int) $game['played']),
				'OWN_SCORE'=>$game['own_units'] !== null ? score::display($game['own_units'], $this->language->lang('PLUS_ARC_DECIMAL')) : '',
				'RECORDED'=>$recorded, 'POINTS'=>$recorded ? score::display($game['score_units'], $this->language->lang('PLUS_ARC_DECIMAL')) : '',
				'PLAYER'=>$recorded ? $this->player($game) : ''));
		}
		if ($has_start)
		{
			add_form_key('plus_arc_tour_play_' . (int) $id);
			$this->template->assign_var('ARC_TURN_TOKEN', $this->template->retrieve_var('S_FORM_TOKEN'));
		}
		if ($view['CAN_JOIN'])
		{
			add_form_key('plus_arc_tour_join');
			$this->template->assign_var('U_JOIN', $this->helper->route('phpbb2plus_arcade_join_tournament', $params));
		}
		$this->template->assign_vars(array('TOUR'=>$view, 'MAX_TURNS'=>(int) $entry['tour_player_turns']));
		return $this->helper->render('@phpbb2plus_arcade/tournament.html', utf8_htmlspecialchars($view['NAME']));
	}

	public function join($id = 0)
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		if (!check_form_key('plus_arc_tour_join')) { throw new AccessDeniedHttpException(); }
		$raw = $id ? array((string) $id) : $this->request->variable('tours', array(''), false, \phpbb\request\request_interface::POST);
		$ids = array();
		foreach ($raw as $value)
		{
			if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int) $value > 2147483647) { throw new NotFoundHttpException(); }
			$ids[] = (int) $value;
		}
		if (!$ids || count($ids) > 100) { return $this->helper->message('PLUS_ARC_TOUR_SELECT', array(), 'INFORMATION', 422); }
		$results = $this->sessions->locked(function () use ($ids) { return $this->tours->join(array_unique($ids)); });
		if ($id)
		{
			if (!isset($results[$id])) { throw new NotFoundHttpException(); }
			$status = $results[$id]['status'];
			if (!in_array($status, array('PLUS_ARC_TOUR_JOINED', 'PLUS_ARC_TOUR_MEMBER'), true))
			{
				return $this->helper->message($status, array(), 'INFORMATION', 409);
			}
			return new RedirectResponse($this->helper->route('phpbb2plus_arcade_tournament', array('id'=>(int) $id), false), 303);
		}
		$this->header('phpbb2plus_arcade_tournaments');
		foreach ($results as $key=>$row)
		{
			$this->template->assign_block_vars('joined', array('NAME'=>(string) $row['name'], 'STATUS'=>$this->language->lang($row['status']),
				'URL'=>$this->helper->route('phpbb2plus_arcade_tournament', array('id'=>$key))));
		}
		if (!$results) { throw new NotFoundHttpException(); }
		$title = $this->language->lang('PLUS_ARC_TOUR_JOIN');
		$this->template->assign_vars(array('ARC_TITLE'=>$title, 'U_TOURS'=>$this->helper->route('phpbb2plus_arcade_tournaments')));
		return $this->helper->render('@phpbb2plus_arcade/tournaments.html', $title);
	}
}
