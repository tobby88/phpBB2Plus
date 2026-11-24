<?php
namespace phpbb2plus\community\migrations;

class v106_profile_media extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\community\migrations\v105_former_names'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_media_issues' => array(
			'COLUMNS' => array('asset_kind' => array('VCHAR:12', ''), 'source_id' => array('UINT', 0),
				'reference_json' => array('TEXT', ''), 'reason' => array('VCHAR:40', '')),
			'PRIMARY_KEY' => array('asset_kind', 'source_id'),
		)));
	}

	public function revert_schema() { return array('drop_tables' => array($this->table_prefix . 'plus_media_issues')); }
}
