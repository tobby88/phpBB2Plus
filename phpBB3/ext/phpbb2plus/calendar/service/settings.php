<?php
namespace phpbb2plus\calendar\service;

class settings
{
	const MEMBER = array('display_open'=>0,'header_cells'=>7,'week_start'=>1,'nb_row'=>5,'birthday'=>1,'forum'=>1);
	const BOARD = array('title_length'=>30,'text_length'=>200);
	private $config;
	private $user;

	public function __construct($config, $user) { $this->config = $config; $this->user = $user; }

	public function get($name)
	{
		$key = 'plus_cal_' . $name;
		if (isset(self::MEMBER[$name]) && empty($this->config[$key . '_over']) && !empty($this->user->data['is_registered']) && isset($this->user->data[$key]))
		{
			return (int) $this->user->data[$key];
		}
		return (int) ($this->config[$key] ?? self::MEMBER[$name] ?? self::BOARD[$name] ?? 0);
	}
}
