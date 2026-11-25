<?php
namespace phpbb2plus\community\migrations;

class v109_legacy_links extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v108_custom_profiles'); }

	public function update_schema()
	{
		$users = $this->table_prefix . 'users';
		$forums = $this->table_prefix . 'forums';
		return array('add_columns'=>array($users=>array('plus_legacy_id'=>array('UINT',0)),
			$forums=>array('plus_legacy_category'=>array('UINT',0))),
			'add_index'=>array($users=>array('plus_old_user'=>array('plus_legacy_id')),
			$forums=>array('plus_old_cat'=>array('plus_legacy_category'))));
	}

	public function update_data()
	{
		return array(array('custom', array(array($this, 'restore_categories'))));
	}

	public function restore_categories()
	{
		$forums = $this->table_prefix . 'forums';
		if ($this->db_tools->sql_column_exists($forums, 'plus_cal_src_cat'))
		{
			$this->db->sql_query("UPDATE $forums SET plus_legacy_category=plus_cal_src_cat WHERE forum_type=" . FORUM_CAT . " AND plus_cal_src_cat>0");
		}
	}

	public function revert_schema()
	{
		$users = $this->table_prefix . 'users';
		$forums = $this->table_prefix . 'forums';
		return array('drop_keys'=>array($users=>array('plus_old_user'), $forums=>array('plus_old_cat')),
			'drop_columns'=>array($users=>array('plus_legacy_id'), $forums=>array('plus_legacy_category')));
	}
}
