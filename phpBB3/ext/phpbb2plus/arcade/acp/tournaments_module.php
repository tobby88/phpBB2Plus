<?php
namespace phpbb2plus\arcade\acp;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\tournament_games;
use phpbb2plus\arcade\service\tournament_management;
use phpbb2plus\arcade\service\tournaments;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class tournaments_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	private $language;
	private $request;
	private $template;
	private $tours;
	private $manager;
	private $games;
	private $lock;

	private function directory()
	{
		$total = $this->tours->count_all();
		$start = min(max(0, $this->request->variable('start', 0)), max(0, (int) floor(($total - 1) / 25) * 25));
		foreach ($this->tours->administration($start, 25) as $event)
		{
			$state = tournaments::STATES[(int) $event['tour_active']] ?? 'UNKNOWN';
			$this->template->assign_block_vars('events', array('NAME'=>$event['tour_name'], 'STATE'=>$this->language->lang($state),
				'PLAYERS'=>(int) $event['players'], 'CAPACITY'=>(int) $event['tour_max_players'],
				'URL'=>$this->u_action . '&amp;event=' . (int) $event['tour_id']));
		}
		$this->template->assign_vars(array('U_NEW'=>$this->u_action . '&amp;new=1',
			'U_PREV'=>$start ? $this->u_action . '&amp;start=' . max(0, $start-25) : '',
			'U_NEXT'=>$start+25 < $total ? $this->u_action . '&amp;start=' . ($start+25) : ''));
	}

	private function game_lists($id, $url, $finished)
	{
		$start = max(0, $this->request->variable('assigned_start', 0));
		$assigned = $this->games->assigned($id, 26, $start);
		foreach (array_slice($assigned, 0, 25) as $game) { $this->template->assign_block_vars('assigned', array('NAME'=>$game['game_name'])); }
		$this->template->assign_vars(array('U_ASSIGNED_PREV'=>$start ? $url . '&amp;assigned_start=' . max(0,$start-25) : '',
			'U_ASSIGNED_NEXT'=>count($assigned) > 25 ? $url . '&amp;assigned_start=' . ($start+25) : ''));
		if ($finished) { return; }
		$search = utf8_substr(htmlspecialchars_decode($this->request->variable('game_search','',true), ENT_QUOTES),0,100);
		$start = max(0, $this->request->variable('game_start', 0));
		$candidates = $this->games->choices($id, $search, 26, $start);
		foreach (array_slice($candidates, 0, 25) as $game)
		{
			$this->template->assign_block_vars('choices', array('NAME'=>catalog::plain($game['game_desc'] ?: $game['game_name']),
				'GAME_KEY'=>$game['game_name'], 'TOKEN'=>tournament_games::token($game)));
		}
		$search_url = $url . '&amp;game_search=' . rawurlencode($search);
		$this->template->assign_vars(array('GAME_SEARCH'=>$search,
			'U_GAMES_PREV'=>$start ? $search_url . '&amp;game_start=' . max(0,$start-25) : '',
			'U_GAMES_NEXT'=>count($candidates) > 25 ? $search_url . '&amp;game_start=' . ($start+25) : ''));
	}

	public function main($module, $mode)
	{
		global $auth, $phpbb_container, $request, $template;
		if (!$auth->acl_get('a_plus_arcade')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$this->language = $phpbb_container->get('language');
		$this->language->add_lang(array('arcade','info_acp_arcade'), 'phpbb2plus/arcade');
		$this->tours = $phpbb_container->get('phpbb2plus.arcade.tournaments');
		$this->manager = $phpbb_container->get('phpbb2plus.arcade.tournament_management');
		$this->games = $phpbb_container->get('phpbb2plus.arcade.tournament_games');
		$this->lock = $phpbb_container->get('phpbb2plus.arcade.sessions');
		$this->request = $request;
		$this->template = $template;
		$this->tpl_name = 'acp_plus_arcade_tournaments';
		$this->page_title = 'ACP_PLUS_ARCADE_TOURNAMENTS';
		$id = $request->variable('event',0,false,request_interface::GET);
		if (!$id && !$request->variable('new',0,false,request_interface::GET)) { $this->directory(); return; }
		$key = 'plus_arc_manage_tour_' . $id;
		$revision = $request->variable('revision','',false,request_interface::POST);
		$nonce = $request->variable('nonce','',false,request_interface::POST);
		$input = null;
		try
		{
			if ($request->is_set_post('save'))
			{
				if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				$input = array();
				foreach (array('name','description','players','turns','reserve','state') as $name)
				{
					$input[$name] = htmlspecialchars_decode($request->variable($name, $name === 'reserve' ? '0' : '', true, request_interface::POST), ENT_QUOTES);
				}
				$saved = $this->lock->locked(function () use ($id,$input,$revision,$nonce) { return $this->manager->edit($id,$input,$revision,$nonce); });
				if (!$id) { redirect($this->u_action . '&amp;event=' . $saved); }
				$template->assign_var('S_SAVED', true);
				$input = null;
			}
			elseif ($id && $request->is_set_post('add_games'))
			{
				if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				$tokens = $request->variable('games', array(''), false, request_interface::POST);
				$this->lock->locked(function () use ($id,$tokens,$revision) { $this->games->add($id,$tokens,$revision); });
				$template->assign_var('S_SAVED', true);
			}
		}
		catch (\DomainException $e)
		{
			$template->assign_var('ARC_ERROR', $this->language->lang($e->getMessage()));
			if ($e->getMessage() === 'PLUS_ARC_EVENT_CHANGED') { $input = null; }
		}
		catch (NotFoundHttpException $e) { trigger_error('NO_MODE', E_USER_WARNING); }
		try { $row = $id ? $this->tours->entry($id) : null; }
		catch (NotFoundHttpException $e) { trigger_error('NO_MODE', E_USER_WARNING); }
		$values = $input ?? ($row ? array('name'=>$row['tour_name'], 'description'=>$row['tour_desc'], 'players'=>$row['tour_max_players'],
			'turns'=>$row['tour_player_turns'], 'reserve'=>$row['block_plays'], 'state'=>$row['tour_active']) :
			array('name'=>'', 'description'=>'', 'players'=>2, 'turns'=>1, 'reserve'=>0, 'state'=>1));
		$url = $this->u_action . ($id ? '&amp;event=' . $id : '&amp;new=1');
		$finished = $row && (int) $row['tour_active'] === 3;
		foreach (tournaments::STATES as $state=>$label)
		{
			if ($state !== 3) { $template->assign_block_vars('states', array('VALUE'=>$state, 'LABEL'=>$this->language->lang($label), 'SELECTED'=>(string) $state === (string) $values['state'])); }
		}
		if ($id) { $this->game_lists($id, $url, $finished); }
		add_form_key($key);
		$template->assign_vars(array('S_EDITOR'=>true, 'S_EXISTING'=>(bool) $id, 'S_FINISHED'=>$finished, 'EVENT'=>$values,
			'REVISION'=>$row ? tournament_management::revision($row) : '', 'NONCE'=>$nonce ?: bin2hex(random_bytes(16)),
			'MAX_PLAYERS'=>max($this->manager->limit('games_tournament_players'), $row ? (int) $row['tour_max_players'] : 0),
			'U_LIST'=>$this->u_action, 'U_EDIT'=>$url));
	}
}
