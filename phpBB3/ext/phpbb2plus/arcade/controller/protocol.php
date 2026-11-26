<?php
namespace phpbb2plus\arcade\controller;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\game_identity;
use phpbb2plus\arcade\service\score;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class protocol
{
	private $sessions;
	private $results;
	private $catalog;
	private $request;
	private $http;
	private $config;
	private $helper;
	private $language;

	public function __construct($sessions, $results, $catalog, $request, $http, $config, $helper, $language)
	{
		$this->sessions = $sessions;
		$this->results = $results;
		$this->catalog = $catalog;
		$this->request = $request;
		$this->http = $http;
		$this->config = $config;
		$this->helper = $helper;
		$this->language = $language;
	}

	private function param($name, $default = '')
	{
		// In particular, a pnFlash saved game is opaque. Keep spaces, quotes and literal entities.
		$source = $this->http->request->has($name) ? $this->http->request : $this->http->query;
		$value = $source->get($name, $default);
		if ($value !== null && !is_string($value)) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		return $value;
	}

	private function token($path)
	{
		$origin = strtolower($this->http->getSchemeAndHttpHost());
		$header = strtolower($this->http->headers->get('Origin', ''));
		$site = strtolower($this->http->headers->get('Sec-Fetch-Site', ''));
		if (($header !== '' && $header !== $origin) || ($site !== '' && !in_array($site, array('same-origin','none'), true)))
		{ throw new NotFoundHttpException(); }
		$explicit = $this->param('arcade_hash');
		if ($path !== '' && $explicit !== '' && !hash_equals($path, $explicit)) { throw new NotFoundHttpException(); }
		if ($path !== '' || $explicit !== '') { return $path !== '' ? $path : $explicit; }
		$ref = $this->http->headers->get('Referer', '');
		$same = $ref !== '' && strncasecmp($ref, $origin . '/', strlen($origin) + 1) === 0;
		if (!$same && $header !== $origin && $site !== 'same-origin') { throw new NotFoundHttpException(); }
		// Absolute old callbacks can still belong to an earlier open game tab.
		if ($same && preg_match('~/arcade/game/[1-9][0-9]*/(?:play|media)/([a-f0-9]{32})(?:/|$)~D', (string) parse_url($ref, PHP_URL_PATH), $m))
		{ return $m[1]; }
		return $this->request->variable($this->config['cookie_name'] . '_plus_arcade', '', false, request_interface::COOKIE);
	}

	private function text($text, $status = 200)
	{
		return new Response($text, $status, array('Content-Type' => 'text/plain; charset=UTF-8'));
	}

	private function scoreboard($game, $launch)
	{
		if ((int) $launch['tour_id'])
		{
			return new RedirectResponse($this->helper->route('phpbb2plus_arcade_tournament', array('id'=>(int) $launch['tour_id']), false) . '#game-' . (int) $game['game_id'], 303);
		}
		return new RedirectResponse($this->helper->route('phpbb2plus_arcade_scores', array('game' => (int) $game['game_id'], 'board' => 'current'), false), 303);
	}

	private function standard($game, $launch, $path)
	{
		$type = (int) $game['score_type'];
		$ib = $this->param('gscore', null);
		$wire = $this->param('score', null);
		$posted = $this->http->isMethod('POST');
		if ($type === 8 || ($type === 3 && $path === '' && $this->param('arcade_hash') === '') ||
			(!$posted && ((int) $this->catalog->setting('games_cheat_mode', 1) || !$this->param('mode'))) ||
			($type === 2 && !$posted)) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		$name = $this->param('game_name', $this->param('gamename', $this->param('gname')));
		if ($name !== '' && !game_identity::accepts($name,$game,$launch))
		{ throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		if ($type === 4 || ($ib !== null && $wire === null)) { $wire = $ib; }
		$this->results->submit($game, $launch, score::from_wire($wire), 'standard');
		return $this->scoreboard($game, $launch);
	}

	private function command($endpoint, $game, $launch, $path)
	{
		if ($endpoint === 'newscore.php') { return $this->standard($game, $launch, $path); }
		if ($endpoint === 'pnFlashGames.php')
		{
			$action = $this->param('func');
			$gid = $this->param('gid', '0');
			if ($gid !== '0' && $gid !== (string) $game['game_id']) { throw new \DomainException('PLUS_ARC_PROGRESS'); }
			if ($action === 'storeScore')
			{
				$units = score::from_wire($this->param('score'));
				if ($units <= 0 || !in_array((int) $game['score_type'], array(0,5,6), true)) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
				$this->results->submit($game, $launch, $units, 'pnflash');
			}
			elseif ($action === 'loadGame')
			{
				return $this->text('gameData=' . rawurlencode($this->results->progress($game, $launch)) . '&opSuccess=true&endvar=1');
			}
			elseif ($action === 'saveGame' && ($data = $this->param('gameData', null)) !== null) { $this->results->progress($game, $launch, $data); }
			else { throw new \DomainException('PLUS_ARC_PROGRESS'); }
			return $this->text('&opSuccess=true&endvar=1');
		}
		if ($endpoint === 'IBProArcade.php')
		{
			if (!in_array((int) $game['score_type'], array(0,4,5,8), true)) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
			$action = $this->param('do');
			if ($action === 'verifyscore') { return $this->text($this->results->challenge($launch)); }
			$units = score::from_wire($this->param('gscore'));
			$encoded = $this->param('enscore');
			if (!in_array($action, array('savescore','newscore'), true) || !$launch['rand_one'] || !$launch['rand_two'] ||
				!preg_match('/^-?[0-9]{1,15}$/D', $encoded) ||
				(string) (((int) ((float) $units * (int) $launch['rand_one'] / 10000)) ^ (int) $launch['rand_two']) !== $encoded)
			{ throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
			$this->results->submit($game, $launch, $units, 'standard');
			return $this->scoreboard($game, $launch);
		}
		if ($endpoint === 'arcade.php' && in_array((int) $game['score_type'], array(0,5,7), true))
		{
			switch ($this->param('sessdo'))
			{
				case 'sessionstart': return $this->text('&connStatus=1&initbar=' . rawurlencode(game_identity::for_launch($game,$launch)) . '&val=x');
				case 'permrequest':
					$units = score::from_wire($this->param('score'));
					return $this->text('&validate=1&microone=' . score::display($units) . '|' . rawurlencode($this->param('fakekey')) . '&val=x');
				case 'burn':
					$parts = explode('|', $this->param('microone'), 2);
					$units = score::from_wire($parts[0]);
					if ($units <= 0) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
					$this->results->submit($game, $launch, $units, 'standard');
					return $this->scoreboard($game, $launch);
			}
		}
		throw new \DomainException('PLUS_ARC_INVALID_SCORE');
	}

	public function index_endpoint()
	{
		if ($this->request->variable('act', '', false, request_interface::GET) === 'Arcade') { return 'newscore.php'; }
		if ($this->request->variable('autocom', '') === 'arcade') { return 'IBProArcade.php'; }
		return in_array($this->request->variable('func', '', false, request_interface::POST), array('storeScore','saveGame','loadGame'), true) ? 'pnFlashGames.php' : '';
	}

	public function handle($endpoint, $game = 0, $launch = '')
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		try
		{
			if ($endpoint === 'index.php') { $endpoint = $this->index_endpoint(); }
			if (!$this->http->isMethod('GET') && !$this->http->isMethod('POST')) { return $this->text('', 405); }
			if ($endpoint === 'arcade.php' && $this->param('sessdo') === '')
			{ return new RedirectResponse($this->helper->route('phpbb2plus_arcade_index', array(), false)); }
			$token = $this->token($launch);
			$response = $this->sessions->locked(function () use ($token, $game, $endpoint, $launch) {
				list($entry, $receipt) = $this->sessions->find($token, $game, true);
				return $this->command($endpoint, $entry, $receipt, $launch);
			});
		}
		catch (NotFoundHttpException $e) { $response = $this->text('', 404); }
		catch (\DomainException $e)
		{
			$response = $endpoint === 'pnFlashGames.php' ? $this->text('&opSuccess=Missing info&endvar=1') : $this->text($this->language->lang($e->getMessage()), 422);
		}
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->headers->set('Referrer-Policy', 'same-origin');
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
