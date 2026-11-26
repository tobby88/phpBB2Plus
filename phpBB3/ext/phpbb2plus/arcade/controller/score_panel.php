<?php
namespace phpbb2plus\arcade\controller;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\score;
use phpbb2plus\arcade\service\score_moderation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class score_panel
{
	private $moderation;
	private $user;
	private $language;
	private $template;
	private $request;
	private $helper;
	private $names;

	public function __construct($moderation, $user, $language, $template, $request, $helper, $names = null)
	{
		$this->moderation = $moderation;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->request = $request;
		$this->helper = $helper;
		$this->names = $names;
	}

	public function front()
	{
		$base = $this->helper->route('phpbb2plus_arcade_moderate', array(), false);
		$result = $this->display($base, false);
		if ($result instanceof RedirectResponse) { return $result; }
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ARC'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_arcade_index')));
		$response = $this->helper->render('@phpbb2plus_arcade/score_moderation.html', $this->language->lang('PLUS_ARC_MOD'), $result);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}

	private static function url($base, $params)
	{
		return $params ? $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : $base;
	}

	private function number($key, $minimum)
	{
		if (!$this->request->is_set($key, request_interface::GET)) { return null; }
		$raw = $this->request->variable($key, '', false, request_interface::GET);
		$id = filter_var($raw, FILTER_VALIDATE_INT, array('options' => array('min_range' => $minimum, 'max_range' => 2147483647)));
		if ($id === false) { throw new NotFoundHttpException(); }
		return $id;
	}

	private function category_name($cat)
	{
		if ((int) $cat['cat_id'] > 0) { return catalog::plain($cat['cat_name']); }
		return $this->language->lang((int) $cat['cat_id'] === -1 ? 'PLUS_ARC_MOD_PENDING' : 'PLUS_ARC_MOD_UNASSIGNED');
	}

	private function player($row)
	{
		return catalog::player_name($row, $this->language, $this->names && $this->names->hidden());
	}

	private function page($base, $params, $start, $size, $total)
	{
		$this->template->assign_vars(array(
			'ARC_PAGE' => $total > $size ? $this->language->lang('PAGE_OF', intdiv($start, $size) + 1, (int) ceil($total / $size)) : '',
			'U_ARC_PREV' => $start ? self::url($base, $params + array('start' => $start - $size)) : '',
			'U_ARC_NEXT' => $start + $size < $total ? self::url($base, $params + array('start' => $start + $size)) : '',
		));
	}

	private function games($base, $params, $start)
	{
		list($rows, $total, $start) = $this->moderation->games($params['category'], 25, $start);
		$this->template->assign_var('S_ARC_GAMES', true);
		foreach ($rows as $game)
		{
			$target = $params + array('game' => (int) $game['game_id']);
			$this->template->assign_block_vars('arc_games', array('TITLE' => catalog::plain($game['game_desc'] ?: $game['game_name']),
				'NAME' => catalog::plain($game['game_name']), 'PATH' => catalog::plain($game['game_path']),
				'IMAGE' => catalog::plain($game['image_path']), 'DETAIL' => catalog::plain($game['instructions']),
				'WIDTH' => (int) $game['win_width'], 'HEIGHT' => (int) $game['win_height'], 'AUTO' => (bool) $game['game_autosize'],
				'U_CURRENT' => self::url($base, $target + array('board' => 'current')), 'U_ALL' => self::url($base, $target + array('board' => 'all'))));
		}
		$this->page($base, $params, $start, 25, $total);
	}

	private function scores($base, $params, $game, $panel, $start)
	{
		list($rows, $total, $start) = $this->moderation->entries($game, $params['board'], 25, $start);
		$this->template->assign_var('S_ARC_SCORES', true);
		foreach ($rows as $i => $row)
		{
			$target = $params + array('entry' => (int) $row['row_id'], 'start' => $start);
			$seconds = max(0, (int) $row['time_taken']);
			$this->template->assign_block_vars('arc_scores', array('RANK' => $start + $i + 1, 'PLAYER' => $this->player($row),
				'SCORE' => $row['score_units'] === null ? '—' : score::display($row['score_units'], $this->language->lang('PLUS_ARC_DECIMAL')),
				'DATE' => $row['recorded_at'] ? $this->user->format_date($row['recorded_at']) : '',
				'DURATION' => sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60),
				'IP' => filter_var($row['player_ip'], FILTER_VALIDATE_IP) ?: '',
				'U_DELETE' => self::url($base, $target + array('action' => 'delete')),
				'U_EDIT' => $panel ? self::url($base, $target + array('action' => 'edit')) : ''));
		}
		$this->page($base, $params, $start, 25, $total);
	}

	private function form($base, $params, $game, $entry, $action, $panel, $start)
	{
		$board = $params['board'];
		$row = $this->moderation->entry($game, $board, $entry);
		$form = implode('_', array('plus_arc_score', $panel ? 'admin' : 'mod', $params['category'], $params['game'], $board, $entry, $action));
		$list_url = self::url($base, $params + ($start ? array('start' => $start) : array()));
		$value = $row['score_units'] === null ? '' : score::display($row['score_units']);
		$status = 200;
		if ($this->request->is_set_post('confirm'))
		{
			if (!check_form_key($form)) { throw new \DomainException('FORM_INVALID'); }
			if ($action === 'edit') { $value = $this->request->variable('score', '', false, request_interface::POST); }
			try
			{
				$this->moderation->change($params['category'], $params['game'], $board, $entry,
					$this->request->variable('revision', '', false, request_interface::POST), $action, $value, $panel);
				return new RedirectResponse($list_url, 303, array('Cache-Control' => 'private, no-store'));
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage() !== 'PLUS_ARC_INVALID_SCORE') { throw $e; }
				$this->template->assign_var('ARC_ERROR', $this->language->lang('PLUS_ARC_MOD_NUMBER'));
				$status = 422;
			}
		}
		add_form_key($form);
		$this->template->assign_vars(array('S_ARC_FORM' => true, 'S_ARC_EDIT' => $action === 'edit',
			'ARC_PLAYER' => $this->player($this->moderation->entry_player($row, $board)), 'ARC_VALUE' => $value,
			'ARC_DATE' => $row['recorded_at'] ? $this->user->format_date($row['recorded_at']) : '',
			'ARC_ORIGINAL' => $row['score_units'] === null ? '—' : score::display($row['score_units'], $this->language->lang('PLUS_ARC_DECIMAL')),
			'ARC_REVISION' => score_moderation::revision($game, $board, $row), 'U_ARC_BOARD' => $list_url,
			'U_ARC_FORM' => self::url($base, $params + array('entry' => $entry, 'start' => $start, 'action' => $action))));
		return $status;
	}

	public function display($base, $panel)
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		$base = html_entity_decode($base, ENT_QUOTES, 'UTF-8');
		$cat = $this->number('category', -1);
		$id = $this->number('game', 1);
		$entry = $this->number('entry', 1);
		$start = $this->number('start', 0) ?? 0;
		$board = $this->request->variable('board', 'current', false, request_interface::GET);
		$action = $this->request->variable('action', '', false, request_interface::GET);
		if (($id && $cat === null) || ($entry && !$id) || ($entry && !$action) || ($action && !$entry) ||
			!in_array($board, array('current','all'), true) || !in_array($action, $panel ? array('','edit','delete') : array('','delete'), true))
		{ throw new NotFoundHttpException(); }
		$this->template->assign_vars(array('U_ARC_CATEGORIES' => $base, 'S_ARC_PANEL' => $panel));
		if ($cat === null)
		{
			$categories = $this->moderation->categories($panel);
			if (!$categories) { throw new NotFoundHttpException(); }
			foreach ($categories as $key => $category)
			{
				$this->template->assign_block_vars('arc_cats', array('TITLE' => $this->category_name($category),
					'URL' => self::url($base, array('category' => $key))));
			}
			return 200;
		}
		$params = array('category' => $cat);
		$category = $this->moderation->category($cat, $panel);
		$this->template->assign_vars(array('ARC_CATEGORY' => $this->category_name($category), 'U_ARC_CAT' => self::url($base, $params)));
		if (!$id) { $this->games($base, $params, $start); return 200; }
		$game = $this->moderation->game($cat, $id);
		$params += array('game' => $id, 'board' => $board);
		$this->template->assign_vars(array('ARC_GAME' => catalog::plain($game['game_desc'] ?: $game['game_name']),
			'ARC_BOARD_LABEL' => $this->language->lang($board === 'all' ? 'PLUS_ARC_ALL' : 'PLUS_ARC_CURRENT'),
			'U_ARC_BOARD' => self::url($base, $params)));
		try
		{
			if ($entry) { return $this->form($base, $params, $game, $entry, $action, $panel, $start); }
			$this->scores($base, $params, $game, $panel, $start);
		}
		catch (\DomainException $e)
		{
			$this->template->assign_var('ARC_ERROR', $this->language->lang($e->getMessage()));
			return $e->getMessage() === 'FORM_INVALID' ? 403 : 409;
		}
		return 200;
	}
}
