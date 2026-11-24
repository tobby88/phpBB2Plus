<?php
namespace phpbb2plus\calendar\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class navigation implements EventSubscriberInterface
{
	private $language;
	private $helper;
	private $template;

	public function __construct($language, $helper, $template)
	{
		$this->language = $language; $this->helper = $helper; $this->template = $template;
	}

	static public function getSubscribedEvents() { return array('core.page_header'=>'header'); }
	public function header()
	{
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$this->template->assign_var('U_PLUS_CALENDAR',$this->helper->route('phpbb2plus_calendar_home'));
	}
}
