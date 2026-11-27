<?php
namespace phpbb2plus\community\migrations;

class v110_portal extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v109_legacy_links'); }
	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'users'=>array(
			'plus_last_login'=>array('TIMESTAMP',0),'plus_login_hidden'=>array('BOOL',0))),
			'add_index'=>array($this->table_prefix . 'users'=>array('plus_login'=>array('plus_last_login'))));
	}
	public function revert_schema()
	{
		return array('drop_keys'=>array($this->table_prefix . 'users'=>array('plus_login')),
			'drop_columns'=>array($this->table_prefix . 'users'=>array('plus_last_login','plus_login_hidden')));
	}
	public function update_data()
	{
		$data = array();
		foreach (array('recent'=>5,'last_seen'=>5,'top_posters'=>5,'shouts'=>1,'stats'=>1) as $name=>$value)
		{
			$data[] = array('config.add',array('plus_portal_' . $name,$value));
		}
		// The editor creates the optional spelling on its first save on previously installed boards.
		foreach (array('welcome','welcome_source','exclude','poll_forums') as $name) { $data[] = array('config_text.add',array('plus_portal_' . $name,'')); }
		$data[] = array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array('module_basename'=>'\phpbb2plus\community\acp\portal_module','modes'=>array('settings'))));
		return $data;
	}
}
