<?php
namespace phpbb2plus\arcade\controller;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class player
{
	private $sessions;
	private $request;
	private $language;
	private $template;
	private $helper;
	private $user;
	private $config;
	private $catalog;

	public function __construct($sessions, $request, $language, $template, $helper, $user, $config, $catalog)
	{
		$this->sessions = $sessions;
		$this->request = $request;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->user = $user;
		$this->config = $config;
		$this->catalog = $catalog;
	}

	public function start($game, $tour = 0)
	{
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		$key = $tour ? 'plus_arc_tour_play_' . (int) $tour : 'plus_arc_play_' . (int) $game;
		if (!check_form_key($key)) { throw new AccessDeniedHttpException(); }
		try { $token = $this->sessions->start($game, $this->request->variable('play_nonce', '', false, request_interface::POST), $tour); }
		catch (\DomainException $e) { return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 422); }
		return new RedirectResponse($this->helper->route('phpbb2plus_arcade_play', array('game'=>(int) $game,'launch'=>$token), false), 303);
	}

	public function page($game, $launch)
	{
		list($g, $receipt) = $this->sessions->find($launch, $game);
		$this->language->add_lang('arcade', 'phpbb2plus/arcade');
		$asset = $this->sessions->asset($game, $g['main_asset']);
		$params = array('game'=>(int) $game,'launch'=>$launch,'name'=>$asset['relative_name']);
		$src = $this->helper->route('phpbb2plus_arcade_asset', $params, false, false);
		$vars = array('arcade_hash'=>$launch, 'game_id'=>(int) $game);
		if ((int) $g['score_type'] === 6)
		{
			$vars += array('pn_gid'=>(int) $game,'pn_uname'=>htmlspecialchars_decode($this->user->data['username'], ENT_QUOTES),
				'pn_licence'=>'None','pn_checksum'=>$asset['legacy_checksum'],'pn_domain'=>preg_replace('/^www\./i','',$this->config['server_name']),
				'pn_script'=>'pnFlashGames.php','pn_modvalue'=>'phpBBArcade','pn_autoupdate'=>'true');
		}
		$src .= (strpos($src,'?') === false ? '?' : '&') . http_build_query($vars, '', '&', PHP_QUERY_RFC3986);
		$title = catalog::plain($g['game_desc'] ?: $g['game_name']);
		if ((int) $receipt['tour_id'])
		{
			$this->template->assign_var('U_ARC_TOUR', $this->helper->route('phpbb2plus_arcade_tournament', array('id'=>(int) $receipt['tour_id'])));
		}
		$type = $asset['mime_type'] === 'application/x-shockwave-flash' ? 'swf' : explode('/', $asset['mime_type'])[0];
		$this->template->assign_vars(array('ARC_TITLE'=>$title, 'ARC_TYPE'=>$type, 'ARC_SOURCE'=>$src,
			'ARC_AUTO_SIZE'=>(int) $this->catalog->setting('games_auto_size',0) && !(int) $g['game_autosize'],
			'ARC_IMAGE_SIZE'=>(int) $g['win_width']>0 && (int) $g['win_height']>0,
			'ARC_WIDTH'=>max(1, min(4096, (int) $g['win_width'] ?: 550)), 'ARC_HEIGHT'=>max(1, min(4096, (int) $g['win_height'] ?: 450)),
			'U_ARC_GAME'=>$this->helper->route('phpbb2plus_arcade_game', array('game'=>(int) $game))));
		$response = $this->helper->render('@phpbb2plus_arcade/player.html', utf8_htmlspecialchars($title));
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->headers->set('Referrer-Policy', 'same-origin');
		return $response;
	}

	public function asset($game, $launch, $name)
	{
		$this->sessions->find($launch, $game);
		$asset = $this->sessions->asset($game, $name);
		$response = new BinaryFileResponse($asset['path'], 200, array('Content-Type'=>$asset['mime_type'],
			'X-Content-Type-Options'=>'nosniff', 'Content-Security-Policy'=>"default-src 'none'; sandbox"), false);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}
}
