<?php
namespace phpbb2plus\community\migrations;

class v155_absence extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v137_account_logins'); }
	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'users'=>array('plus_away'=>array('BOOL',0),
			'plus_away_mode'=>array('INT:11',0),'plus_away_revision'=>array('VCHAR:32',''))),
			'add_tables'=>array($this->table_prefix . 'plus_away_notes'=>array('COLUMNS'=>array(
				'user_id'=>array('UINT',0),'note_text'=>array('MTEXT_UNI','')),'PRIMARY_KEY'=>'user_id')));
	}
	public function update_data()
	{
		return array(array('permission.add',array('u_plus_away_mod')),array('permission.permission_set',array('GLOBAL_MODERATORS','u_plus_away_mod','group')),
			array('config.add',array('plus_away_members',0)),array('config.add',array('plus_away_mod_contact',0)),
			array('config.add',array('plus_away_by_name',1)),array('module.add',array('acp','ACP_CAT_USERS',array(
				'module_basename'=>'\phpbb2plus\community\acp\absence_module','modes'=>array('edit')))));
	}
	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'users'=>array('plus_away','plus_away_mode','plus_away_revision')),
			'drop_tables'=>array($this->table_prefix . 'plus_away_notes'));
	}
}
