<?php
namespace phpbb2plus\calendar\event;

use phpbb\request\request_interface;
use phpbb2plus\calendar\service\settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class preferences implements EventSubscriberInterface
{
	private $settings;
	private $request;
	private $language;
	private $template;

	public function __construct($settings, $request, $language, $template)
	{
		$this->settings = $settings;
		$this->request = $request;
		$this->language = $language;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.ucp_prefs_view_data'=>'member_form', 'core.ucp_prefs_view_update_data'=>'member_save',
			'core.acp_board_config_edit_add'=>'board_form');
	}

	private function label($name)
	{
		return $name === 'birthday' ? 'PLUS_CAL_SHOW_BIRTHDAY' : 'PLUS_CAL_' . strtoupper($name);
	}

	public function member_form($event)
	{
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		foreach (settings::MEMBER as $name=>$default)
		{
			if (!$this->settings->customisable($name)) { continue; }
			$key = 'plus_cal_' . $name;
			$value = $this->settings->get($name);
			if ($event['submit'] && $this->request->is_set_post($key))
			{
				$value = $this->settings->limit($name,$this->request->variable($key,$value,false,request_interface::POST));
			}
			$this->template->assign_block_vars('plus_cal_pref',array('KEY'=>$key, 'LABEL'=>$this->language->lang($this->label($name)),
				'VALUE'=>$value, 'MAX'=>settings::MAXIMUM[$name]));
		}
	}

	public function member_save($event)
	{
		$sql = $event['sql_ary'];
		foreach (settings::MEMBER as $name=>$default)
		{
			$key = 'plus_cal_' . $name;
			if ($this->settings->customisable($name) && $this->request->is_set_post($key))
			{
				$sql[$key] = $this->settings->limit($name,$this->request->variable($key,$default,false,request_interface::POST));
			}
		}
		$event['sql_ary'] = $sql;
	}

	public function board_form($event)
	{
		if ($event['mode'] !== 'features') { return; }
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$display = $event['display_vars'];
		$calendar = array('legend_plus_calendar'=>'PLUS_CALENDAR');
		foreach (settings::MEMBER + settings::BOARD as $name=>$default)
		{
			$key = 'plus_cal_' . $name;
			$max = settings::MAXIMUM[$name];
			$calendar[$key] = array('lang'=>$this->label($name), 'validate'=>$max === 1 ? 'bool' : 'int:0:' . $max,
				'type'=>$max === 1 ? 'radio:yes_no' : 'number:0:' . $max, 'explain'=>false);
			if (isset(settings::MEMBER[$name]))
			{
				$calendar[$key . '_over'] = array('lang'=>'PLUS_CAL_OVERRIDE','validate'=>'bool','type'=>'radio:yes_no','explain'=>false);
			}
		}
		$fields = array();
		foreach ($display['vars'] as $name=>$row)
		{
			if ($row === 'ACP_SUBMIT_CHANGES') { $fields += $calendar; }
			$fields[$name] = $row;
		}
		$display['vars'] = $fields + $calendar;
		$event['display_vars'] = $display;
	}
}
