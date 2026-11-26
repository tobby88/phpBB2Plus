<?php
namespace phpbb2plus\calendar\service;

class settings
{
	const MEMBER = array('display_open'=>0,'header_cells'=>7,'week_start'=>1,'nb_row'=>5,'birthday'=>1,'forum'=>1);
	const BOARD = array('title_length'=>30,'text_length'=>200,'birthday_days'=>7);
	const MAXIMUM = array('display_open'=>1,'header_cells'=>255,'week_start'=>1,'nb_row'=>255,
		'birthday'=>1,'forum'=>1,'title_length'=>255,'text_length'=>32767,'birthday_days'=>366);
	private $config;
	private $user;

	public function __construct($config, $user) { $this->config = $config; $this->user = $user; }

	public function get($name)
	{
		$key = 'plus_cal_' . $name;
		if ($this->customisable($name) && !empty($this->user->data['is_registered']) && isset($this->user->data[$key]))
		{
			return $this->limit($name,$this->user->data[$key]);
		}
		return $this->limit($name,$this->config[$key] ?? self::MEMBER[$name] ?? self::BOARD[$name]);
	}

	public function customisable($name)
	{
		return isset(self::MEMBER[$name]) && empty($this->config['plus_cal_' . $name . '_over']);
	}

	public function limit($name, $value)
	{
		return min(self::MAXIMUM[$name],max(0,(int) $value));
	}
}
