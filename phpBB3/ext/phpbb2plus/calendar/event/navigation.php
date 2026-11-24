<?php
namespace phpbb2plus\calendar\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class navigation implements EventSubscriberInterface
{
	private $language;
	private $helper;
	private $template;
	private $settings;

	public function __construct($language, $helper, $template, $settings)
	{
		$this->language = $language; $this->helper = $helper; $this->template = $template;
		$this->settings = $settings;
	}

	static public function getSubscribedEvents() { return array('core.page_header'=>'header'); }
	public function header()
	{
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$this->template->assign_var('U_PLUS_CALENDAR',$this->helper->route('phpbb2plus_calendar_home'));
		if ($this->settings->get('header_cells'))
		{
			$this->template->assign_vars(array('U_PLUS_CAL_HEADER'=>$this->helper->route('phpbb2plus_calendar_header'),
				'S_PLUS_CAL_OPEN'=>(bool) $this->settings->get('display_open')));
		}
	}
}
