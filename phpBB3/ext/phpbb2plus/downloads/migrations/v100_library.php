<?php
namespace phpbb2plus\downloads\migrations;

class v100_library extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_dl_';
		$id = array('UINT:10', 0);
		$serial = array('UINT:10', null, 'auto_increment');
		$text = array('MTEXT_UNI', '');
		$flag = array('BOOL', 0);
		$time = array('TIMESTAMP', 0);
		$source = array('source_json' => $text);
		$file = array('storage_name' => array('VCHAR:80', ''), 'file_bytes' => array('BINT', 0), 'asset_status' => array('VCHAR:16', 'missing'));
		return array('add_tables' => array(
			$p . 'categories' => array('COLUMNS' => array('cat_id' => $serial, 'parent_id' => array('INT:11', 0), 'position' => array('INT:11', 0),
				'title' => $text, 'summary' => $text, 'allow_files' => $flag, 'allow_ratings' => $flag, 'allow_comments' => $flag,
				'access_levels' => $text) + $source, 'PRIMARY_KEY' => 'cat_id', 'KEYS' => array('dl_tree' => array('INDEX', array('parent_id','position')))),
			$p . 'settings' => array('COLUMNS' => array('config_name' => array('VCHAR:191', ''), 'config_value' => $text), 'PRIMARY_KEY' => 'config_name'),
			$p . 'grants' => array('COLUMNS' => array('row_id' => $serial, 'cat_id' => $id, 'legacy_group' => $id, 'subject_type' => array('CHAR:1', ''),
				'subject_id' => $id, 'allowed' => $text) + $source, 'PRIMARY_KEY' => 'row_id', 'KEYS' => array('dl_grant' => array('INDEX', array('subject_type','subject_id','cat_id')))),
			$p . 'licenses' => array('COLUMNS' => array('license_id' => $serial, 'title' => $text, 'terms' => $text) + $source, 'PRIMARY_KEY' => 'license_id'),
			$p . 'files' => array('COLUMNS' => array('file_id' => $serial, 'cat_id' => $id, 'owner_id' => $id, 'license_id' => $id, 'title' => $text, 'summary' => $text,
				'added_at' => $time, 'updated_at' => $time, 'last_download' => $time, 'downloads' => $id, 'pinned' => $flag, 'approved' => $flag, 'broken' => $flag,
				'preview_name' => array('VCHAR:80', ''), 'preview_type' => array('VCHAR:40', '')) + $file + $source, 'PRIMARY_KEY' => 'file_id',
				'KEYS' => array('dl_category' => array('INDEX', array('cat_id','approved','added_at')), 'dl_owner' => array('INDEX','owner_id'))),
			$p . 'mirrors' => array('COLUMNS' => array('mirror_id' => $serial, 'file_id' => $id, 'location' => $text) + $file + $source,
				'PRIMARY_KEY' => 'mirror_id', 'KEYS' => array('dl_mirror' => array('INDEX', 'file_id'))),
			$p . 'fields' => array('COLUMNS' => array('field_id' => $serial, 'position' => array('INT:11', 0), 'field_type' => array('USINT', 0),
				'title' => $text, 'description' => $text, 'options_json' => $text) + $source, 'PRIMARY_KEY' => 'field_id'),
			$p . 'field_values' => array('COLUMNS' => array('row_id' => $serial, 'file_id' => $id, 'field_id' => $id, 'values_json' => $text) + $source,
				'PRIMARY_KEY' => 'row_id', 'KEYS' => array('dl_value' => array('INDEX', array('file_id','field_id')))),
			$p . 'comments' => array('COLUMNS' => array('comment_id' => $serial, 'file_id' => $id, 'owner_id' => $id, 'posted_at' => $time,
				'title' => $text, 'body' => $text) + $source, 'PRIMARY_KEY' => 'comment_id', 'KEYS' => array('dl_comment' => array('INDEX', array('file_id','posted_at')))),
			$p . 'votes' => array('COLUMNS' => array('row_id' => $serial, 'file_id' => $id, 'owner_id' => $id, 'ip_address' => array('VCHAR:45', ''),
				'points' => array('USINT', 0)) + $source, 'PRIMARY_KEY' => 'row_id', 'KEYS' => array('dl_vote' => array('INDEX', array('file_id','owner_id')))),
			$p . 'visitors' => array('COLUMNS' => array('row_id' => $serial, 'file_id' => $id, 'owner_id' => $id, 'ip_address' => array('VCHAR:45', '')) + $source,
				'PRIMARY_KEY' => 'row_id', 'KEYS' => array('dl_visitor' => array('INDEX', array('file_id','owner_id')))),
			$p . 'import_journal' => array('COLUMNS' => array('operation_id' => array('CHAR:32', ''), 'item_index' => $id, 'item_state' => $id) + $source,
				'PRIMARY_KEY' => array('operation_id','item_index')),
		));
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_downloads')), array('permission.permission_set', array('ROLE_ADMIN_FULL','a_plus_downloads')),
			array('permission.permission_set', array('ROLE_ADMIN_STANDARD','a_plus_downloads')), array('config.add', array('plus_download_media_key', bin2hex(random_bytes(32)))));
	}

	public function revert_schema() { return array('drop_tables' => array_keys($this->update_schema()['add_tables'])); }
}
