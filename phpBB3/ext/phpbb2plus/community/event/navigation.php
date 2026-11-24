<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class navigation implements EventSubscriberInterface
{
	private $helper;
	private $template;
	private $language;
	private $request;

	public function __construct($helper, $template, $language, $request)
	{
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->request = $request;
	}

	static public function getSubscribedEvents()
	{
		return array('core.page_header' => 'header', 'core.index_modify_page_title' => 'home');
	}

	public function header()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$this->template->assign_var('U_PLUS_PORTAL', $this->helper->route('phpbb2plus_community_portal'));
	}

	public function home()
	{
		$uri = $this->request->server('REQUEST_URI', '');
		$method = $this->request->server('REQUEST_METHOD', '');
		if (in_array($method, array('HEAD', 'GET'), true) && $uri !== '' && substr($uri, -1) === '/' && strpos($uri, '?') === false)
		{
			redirect($this->helper->route('phpbb2plus_community_portal', array(), false));
		}
	}
}
