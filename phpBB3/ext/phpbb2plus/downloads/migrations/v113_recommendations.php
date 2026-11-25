<?php
namespace phpbb2plus\downloads\migrations;

class v113_recommendations extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v112_search'); }

	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_dl_mail_receipts'=>array('COLUMNS'=>array(
			'receipt'=>array('CHAR:64',''),'sender_key'=>array('CHAR:64',''),'attempted_at'=>array('TIMESTAMP',0),
			'finished_at'=>array('TIMESTAMP',0),'status'=>array('USINT',0)),
			'PRIMARY_KEY'=>'receipt','KEYS'=>array('dl_mail_sender'=>array('INDEX',array('sender_key','attempted_at')),
				'dl_mail_expire'=>array('INDEX','attempted_at')))));
	}

	public function revert_schema() { return array('drop_tables'=>array($this->table_prefix . 'plus_dl_mail_receipts')); }
}
