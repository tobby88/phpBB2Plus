<?php
namespace phpbb2plus\community\migrations;

class v108_custom_profiles extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v107_flags'); }

	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'profile_fields'=>array(
			'plus_source_id'=>array('UINT',0), 'plus_view_profile'=>array('BOOL',1),
			'plus_profile_position'=>array('USINT',0), 'plus_topic_position'=>array('USINT',0),
			'plus_paused_type'=>array('BOOL',0), 'plus_paused_view'=>array('BOOL',0))));
	}

	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'profile_fields'=>array(
			'plus_source_id','plus_view_profile','plus_profile_position','plus_topic_position','plus_paused_type','plus_paused_view')));
	}

	public function revert_data()
	{
		return array(array('custom',array(array($this,'purge_multiple'))));
	}

	public function purge_multiple()
	{
		global $phpbb_container;
		$this->db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . " SET field_type='profilefields.type.plus_multi'
			WHERE plus_paused_type=1 AND field_type='profilefields.type.text'");
		$phpbb_container->get('profilefields.manager')->purge_profilefields('profilefields.type.plus_multi');
	}
}
