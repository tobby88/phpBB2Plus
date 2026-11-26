<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\smilie_sign;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class smilie_signs implements EventSubscriberInterface
{
	private $paths, $config, $template, $language, $helper;

	public function __construct($paths, $config, $template, $language, $helper)
	{
		$this->paths = $paths; $this->config = $config; $this->template = $template; $this->language = $language; $this->helper = $helper;
	}

	public static function getSubscribedEvents()
	{
		return array('core.text_formatter_s9e_configure_after' => 'configure', 'core.text_formatter_s9e_render_before' => 'render',
			'core.page_header' => 'button');
	}

	public function configure($event) { smilie_sign::configure($event['configurator']); }

	public function render($event)
	{
		if (strpos($event['xml'], '<PLUSSMILIESIGN ') === false) { return; }
		$renderer = $event['renderer'];
		$faces = smilie_sign::faces();
		$renderer->get_renderer()->setParameter('PLUS_SIGN_ASSETS', $faces ? $this->paths->get_web_root_path() . 'smilie_creator/images/smilies/schild/' : '');
		$censor = $renderer->get_viewcensors() && function_exists('censor_text');
		if ($faces || $censor)
		{
			$event['xml'] = \s9e\TextFormatter\Utils::replaceAttributes($event['xml'], 'PLUSSMILIESIGN', static function ($attrs) use ($faces, $censor) {
				if ($faces && ($attrs['face'] ?? '') === 'random') { $attrs['face'] = (string) $faces[random_int(0, count($faces) - 1)]; }
				elseif ($faces && !in_array((int) ($attrs['face'] ?? 0), $faces, true)) { $attrs['face'] = (string) $faces[0]; }
				if ($censor && isset($attrs['caption'])) { $attrs['caption'] = censor_text($attrs['caption']); }
				return $attrs;
			});
		}
		$this->template->assign_var('S_PLUS_SMILIE_SIGNS', (bool) $faces);
	}

	public function button()
	{
		if (!$this->config['allow_bbcode']) { return; }
		$this->language->add_lang('smilie_creator', 'phpbb2plus/community');
		$this->template->assign_var('U_PLUS_SMILIE_CREATOR', $this->helper->route('phpbb2plus_community_smilie_creator'));
	}
}
