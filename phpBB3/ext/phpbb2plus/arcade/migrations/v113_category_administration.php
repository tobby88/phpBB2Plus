<?php
namespace phpbb2plus\arcade\migrations;

class v113_category_administration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v112_category_artwork'); }

	public function update_schema()
	{
		$cats = $this->table_prefix . 'plus_arc_categories';
		// One source character can occupy ten ASCII characters in native NCR form.
		return array('add_columns' => array($cats => array('native_group_id' => array('UINT', 0))),
			'change_columns' => array($cats => array('cat_name' => array('VCHAR_UNI:1000', null), 'cat_icon' => array('VCHAR_UNI:2550', null))));
	}

	public function revert_schema()
	{
		$table = $this->table_prefix . 'plus_arc_categories';
		// Older readers can use the wider fields. Keep in-use native group IDs
		// for a subsequent upgrade; the legacy group marker stays closed.
		if (!$this->db_tools->sql_column_exists($table, 'native_group_id')) { return array(); }
		$result = $this->db->sql_query_limit("SELECT cat_id FROM $table WHERE native_group_id<>0", 1);
		$in_use = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $in_use ? array() : array('drop_columns' => array($table => array('native_group_id')));
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_arc_categories', true, 'a_plus_arcade')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array('module_basename' => '\phpbb2plus\arcade\acp\categories_module',
				'modes' => array('manage')))), array('custom', array(array($this, 'global_category'))));
	}

	public function global_category()
	{
		\phpbb2plus\arcade\service\category_history::ensure_global($this->db, $this->table_prefix . 'plus_arc_');
	}
}
