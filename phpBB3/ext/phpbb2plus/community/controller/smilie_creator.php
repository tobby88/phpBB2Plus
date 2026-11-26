<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\smilie_sign;
use phpbb\request\request_interface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class smilie_creator
{
	private $config, $request, $paths, $helper, $template, $language;

	public function __construct($config, $request, $paths, $helper, $template, $language)
	{
		$this->config = $config; $this->request = $request; $this->paths = $paths; $this->helper = $helper; $this->template = $template; $this->language = $language;
	}

	public function show()
	{
		if (!$this->config['allow_bbcode']) { throw new NotFoundHttpException(); }
		$this->language->add_lang('smilie_creator', 'phpbb2plus/community');
		$sent = $this->request->is_set_post('make');
		$text = html_entity_decode($this->request->variable('caption', '', true, request_interface::POST), ENT_QUOTES, 'UTF-8');
		$input = $this->request->variable('face', 'random', false, request_interface::POST);
		$face = $input === 'standard' ? 'standard' : smilie_sign::face($input);
		$colour = smilie_sign::colour($this->request->variable('fontcolor', '000000', false, request_interface::POST)) ?: '000000';
		$shadow = !$sent ? 'c0c0c0' : ($this->request->is_set_post('textshadow') ?
			smilie_sign::colour($this->request->variable('shadowcolor', 'c0c0c0', false, request_interface::POST)) : '');
		$shade = $this->request->variable('shieldshadow', 1, false, request_interface::POST) ? 1 : 0;
		$caption = smilie_sign::caption($text);
		$error = $sent && ($caption === '' || utf8_strlen($text) > 396 || stripos($text, '[/schild]') !== false);
		$markup = '';
		if ($sent && !$error)
		{
			$markup = '[schild=' . $face . ' fontcolor=' . $colour . ($shadow ? ' shadowcolor=' . $shadow : '')
				. ' shieldshadow=' . $shade . ']' . $caption . '[/schild]';
			$rendered = $markup;
			$uid = $bits = ''; $flags = 0;
			generate_text_for_storage($rendered, $uid, $bits, $flags, true, false, false);
			$this->template->assign_var('PLUS_SIGN_PREVIEW', generate_text_for_display($rendered, $uid, $bits, $flags));
		}
		$this->template->assign_vars(array('PLUS_SIGN_FACES' => smilie_sign::faces(), 'PLUS_SIGN_FACE' => $face, 'PLUS_SIGN_TEXT' => $text,
			'PLUS_SIGN_COLOR' => $colour, 'PLUS_SIGN_SHADOW' => $shadow, 'PLUS_SIGN_SHADE' => $shade, 'PLUS_SIGN_MARKUP' => $markup,
			'S_PLUS_SIGN_ERROR' => $error, 'U_PLUS_SIGN_ASSETS' => $this->paths->get_web_root_path() . 'smilie_creator/images/smilies/schild/',
			'U_PLUS_SIGN_FORM' => $this->helper->route('phpbb2plus_community_smilie_creator')));
		$response = $this->helper->render('@phpbb2plus_community/smilie_creator.html', $this->language->lang('PLUS_SIGN_CREATOR'), $error ? 422 : 200);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}
}
