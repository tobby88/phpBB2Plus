<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class shout_panel implements EventSubscriberInterface
{
	private $shouts;
	private $helper;
	private $template;
	private $language;

	public function __construct($shouts, $helper, $template, $language)
	{
		$this->shouts = $shouts;
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.index_modify_page_title' => array('index', -5), 'phpbb2plus.community.portal_content' => 'portal');
	}

	public function index()
	{
		$this->display(true);
	}

	public function portal()
	{
		$this->display(false);
	}

	private function display($on_index)
	{
		if (!$this->shouts->panel_visible($on_index)) { return; }
		$this->language->add_lang('community', 'phpbb2plus/community');
		foreach ($this->shouts->listing(5) as $entry) { $this->template->assign_block_vars('plus_shouts', $entry); }
		$this->template->assign_var('U_PLUS_SHOUTBOX', $this->helper->route('phpbb2plus_community_shouts'));
		if ($this->shouts->may_post())
		{
			add_form_key('phpbb2plus/shout', '_PLUS_SHOUT');
			$this->template->assign_vars(array('U_PLUS_SHOUT_QUICK' => $this->helper->route('phpbb2plus_community_shout_write'),
				'PLUS_SHOUT_PLACE' => $on_index ? 'index' : 'portal'));
		}
	}
}
