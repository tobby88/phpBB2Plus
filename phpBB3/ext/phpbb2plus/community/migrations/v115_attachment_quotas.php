<?php
namespace phpbb2plus\community\migrations;

class v115_attachment_quotas extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v114_removed_posts'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_attach_';
		$id = array('UINT',0);
		$auto = array('UINT:10',null,'auto_increment');
		return array('add_tables'=>array(
			$p . 'limits'=>array('COLUMNS'=>array('limit_id'=>$auto,'label'=>array('VCHAR_UNI',''),'bytes'=>array('DECIMAL:22',0)), 'PRIMARY_KEY'=>'limit_id'),
			$p . 'quotas'=>array('COLUMNS'=>array('assignment_id'=>$auto,'user_id'=>$id,'group_id'=>$id,'source_user'=>$id,'source_group'=>$id,
				'kind'=>array('INT:11',0),'limit_id'=>$id),'PRIMARY_KEY'=>'assignment_id',
				'KEYS'=>array('member_kind'=>array('INDEX',array('user_id','kind')),'group_kind'=>array('INDEX',array('group_id','kind')))),
			$p . 'payers'=>array('COLUMNS'=>array('attach_id'=>$id,'source_sender'=>array('INT:11',0),'source_recipient'=>array('INT:11',0),
				'sender'=>$id,'recipient'=>$id,'pm_state'=>array('INT:11',-1)), 'PRIMARY_KEY'=>array('attach_id','source_sender','source_recipient'),
				'KEYS'=>array('sender'=>array('INDEX','sender'),'recipient'=>array('INDEX','recipient'))),
			$p . 'pending'=>array('COLUMNS'=>array('physical'=>array('VCHAR:255',''),'user_id'=>$id,'in_message'=>array('BOOL',0),
				'bytes'=>array('BINT',0),'expires'=>array('TIMESTAMP',0)), 'PRIMARY_KEY'=>'physical',
				'KEYS'=>array('owner'=>array('INDEX',array('user_id','in_message','expires')),'expiry'=>array('INDEX','expires')))));
	}
	public function revert_schema()
	{
		$p = $this->table_prefix . 'plus_attach_';
		return array('drop_tables'=>array($p . 'pending',$p . 'payers',$p . 'quotas',$p . 'limits'));
	}
	public function update_data()
	{
		return array(array('config.add',array('plus_attach_quotas',0)),array('config.add',array('plus_attach_default_post',0)),
			array('config.add',array('plus_attach_default_pm',0)));
	}
}
