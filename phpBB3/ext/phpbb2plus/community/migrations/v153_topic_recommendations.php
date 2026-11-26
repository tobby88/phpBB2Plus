<?php
namespace phpbb2plus\community\migrations;

class v153_topic_recommendations extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v138_login_administration'); }
	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'users'=>array('plus_mail_success'=>array('TIMESTAMP',0))),
			'add_tables'=>array($this->table_prefix . 'plus_friend_mail'=>array(
				'COLUMNS'=>array('receipt'=>array('CHAR:64',''),'attempted'=>array('TIMESTAMP',0),'status'=>array('USINT',0)),
				'PRIMARY_KEY'=>'receipt','KEYS'=>array('friend_expiry'=>array('INDEX','attempted')))));
	}
	public function update_data()
	{
		return array(array('config.add',array('plus_friend_protection',1)),array('config.add',array('plus_friend_minutes',20)),
			array('config.add',array('plus_friend_source_board','')),array('config.add',array('plus_friend_read_acl',0)),
			array('custom',array(array($this,'recent_mail'))));
	}
	public function recent_mail()
	{
		// A previous phpBB3 installation only knew the native timestamp. Preserve recent mail during the upgrade.
		$now=time();
		$this->db->sql_query('UPDATE ' . $this->table_prefix . "users SET plus_mail_success=user_emailtime
			WHERE plus_mail_success=0 AND user_emailtime BETWEEN " . ($now-10800) . " AND $now");
	}
	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'users'=>array('plus_mail_success')),
			'drop_tables'=>array($this->table_prefix . 'plus_friend_mail'));
	}
}
