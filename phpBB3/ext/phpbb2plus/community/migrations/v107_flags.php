<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\profile\country_flags;

class v107_flags extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\community\migrations\v106_profile_media'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'profile_fields_data' => array('pf_plus_country' => array('UINT', 1))),
			'add_tables' => array($this->table_prefix . 'plus_flags' => array('COLUMNS' => array(
				'option_value'=>array('UINT',0),'source_id'=>array('UINT',0),'source_file'=>array('VCHAR_UNI',''),
				'label'=>array('VCHAR_UNI',''),'image'=>array('VCHAR:70','')), 'PRIMARY_KEY'=>'option_value')));
	}

	public function update_data() { return array(array('custom',array(array($this,'install_options')))); }

	public function install_options()
	{
		$file = dirname(__DIR__) . '/data/flags.json';
		$flags = is_file($file) ? json_decode(file_get_contents($file),true) : null;
		if (!is_array($flags) || !$flags) { throw new \RuntimeException('The assembled Plus flag catalogue is missing.'); }
		(new country_flags($this->db,$this->table_prefix))->replace($flags);
	}

	public function revert_data() { return array(array('custom',array(array($this,'remove_field')))); }

	public function remove_field()
	{
		$id = (new country_flags($this->db,$this->table_prefix))->field_id();
		if (!$id) { return; }
		foreach (array(PROFILE_FIELDS_LANG_TABLE,PROFILE_LANG_TABLE,PROFILE_FIELDS_TABLE) as $table)
		{
			$this->db->sql_query("DELETE FROM $table WHERE field_id=$id");
		}
	}

	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'profile_fields_data'=>array('pf_plus_country')),
			'drop_tables'=>array($this->table_prefix . 'plus_flags'));
	}
}
