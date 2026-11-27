<?php
namespace phpbb2plus\community\migrations;

class v191_account_references extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v188_post_integrity'); }

	public function update_schema()
	{
		return array('add_index'=>array(
			$this->table_prefix . 'privmsgs_to'=>array('plus_ref_mail'=>array('msg_id','user_id','folder_id','author_id')),
			$this->table_prefix . 'user_group'=>array('plus_ref_group'=>array('user_id','group_id'))));
	}

	public function revert_schema()
	{
		return array('drop_keys'=>array($this->table_prefix . 'privmsgs_to'=>array('plus_ref_mail'),
			$this->table_prefix . 'user_group'=>array('plus_ref_group')));
	}

	public function update_data()
	{
		return array(array('module.add', array('acp','ACP_CAT_DATABASE',array(
			'module_basename'=>'\phpbb2plus\community\acp\post_references_module', 'modes'=>array('accounts')))));
	}
}
