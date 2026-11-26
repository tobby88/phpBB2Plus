<?php
namespace phpbb2plus\weblinks\migrations;

class v101_administration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\weblinks\migrations\v100_directory'); }

	public function update_data()
	{
		$changes = array(array('config.add',array('plus_wl_category_serial',0,true)));
		$changes[] = array('custom',array(array($this,'standard_logo')));
		foreach (\phpbb2plus\weblinks\service\management::RIGHTS as $right)
		{
			$changes[] = array('permission.add',array($right));
			$changes[] = array('permission.permission_set',array('ROLE_ADMIN_FULL',$right));
		}
		$changes[] = array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array(
			'module_basename'=>'\phpbb2plus\weblinks\acp\management_module','modes'=>array('create','entries','categories','settings'))));
		return $changes;
	}

	public function standard_logo()
	{
		// Existing converted logos keep their stored bytes. The native distribution ships the same default button.
		if ($this->config['plus_wl_site_logo'] === '' && $this->config['plus_wl_local_logo'] === '')
		{ $this->config->set('plus_wl_local_logo','default'); }
	}
}
