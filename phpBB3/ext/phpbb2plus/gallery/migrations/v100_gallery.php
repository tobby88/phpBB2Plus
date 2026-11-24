<?php
namespace phpbb2plus\gallery\migrations;

class v100_gallery extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_album_';
		$category = array('cat_id' => array('UINT', null, 'auto_increment'), 'parent_id' => array('UINT', 0),
			'owner_id' => array('UINT', 0), 'source_owner' => array('UINT', 0), 'title' => array('TEXT_UNI', ''),
			'description' => array('MTEXT_UNI', ''), 'sort_order' => array('INT:11', 0), 'approval' => array('TINT:2', 1));
		foreach (array('view', 'upload', 'rate', 'comment', 'edit', 'delete') as $action) { $category[$action . '_level'] = array('TINT:2', 1); }
		return array('add_tables' => array(
			$p . 'cats' => array('COLUMNS' => $category, 'PRIMARY_KEY' => 'cat_id',
				'KEYS' => array('parent' => array('INDEX', array('parent_id','sort_order')), 'owner' => array('INDEX', 'owner_id'))),
			$p . 'grants' => array('COLUMNS' => array('cat_id' => array('UINT', 0), 'capability' => array('VCHAR:12', ''),
				'subject_type' => array('CHAR:1', ''), 'subject_id' => array('UINT', 0)),
				'PRIMARY_KEY' => array('cat_id','capability','subject_type','subject_id'),
				'KEYS' => array('subject' => array('INDEX', array('subject_type', 'subject_id')))),
			$p . 'pics' => array('COLUMNS' => array('pic_id' => array('UINT:10', null, 'auto_increment'), 'cat_id' => array('UINT', 0),
				'author_id' => array('UINT', 0), 'author_name' => array('VCHAR_UNI', ''), 'author_removed' => array('BOOL', 0), 'author_ip' => array('VCHAR:45', ''),
				'title' => array('TEXT_UNI', ''), 'description' => array('MTEXT_UNI', ''), 'created_at' => array('TIMESTAMP', 0),
				'views' => array('UINT:10', 0), 'locked' => array('BOOL', 0), 'approved' => array('BOOL', 0),
				'storage_name' => array('VCHAR:80', ''), 'source_filename' => array('VCHAR_UNI', ''), 'media_type' => array('VCHAR:10', ''),
				'width' => array('UINT', 0), 'height' => array('UINT', 0), 'bytes' => array('UINT:20', 0)),
				'PRIMARY_KEY' => 'pic_id', 'KEYS' => array('cat_time' => array('INDEX', array('cat_id','created_at','pic_id')))),
			$p . 'comments' => array('COLUMNS' => array('comment_id' => array('UINT:10', null, 'auto_increment'),
				'pic_id' => array('UINT:10', 0), 'cat_id' => array('UINT', 0), 'author_id' => array('UINT', 0),
				'author_name' => array('VCHAR_UNI', ''), 'author_removed' => array('BOOL', 0), 'author_ip' => array('VCHAR:45', ''), 'created_at' => array('TIMESTAMP', 0),
				'body' => array('MTEXT_UNI', ''), 'edited_at' => array('TIMESTAMP', 0), 'edit_count' => array('UINT', 0),
				'editor_id' => array('UINT', 0)), 'PRIMARY_KEY' => 'comment_id',
				'KEYS' => array('picture_time' => array('INDEX', array('pic_id','created_at','comment_id')))),
			$p . 'ratings' => array('COLUMNS' => array('rating_id' => array('UINT:10', null, 'auto_increment'),
				'pic_id' => array('UINT:10', 0), 'user_id' => array('UINT', 0), 'user_ip' => array('VCHAR:45', ''),
				'points' => array('USINT', 0), 'honour_points' => array('INT:11', 0)),
				'PRIMARY_KEY' => 'rating_id', 'KEYS' => array('pic_user' => array('INDEX', array('pic_id','user_id')))),
			$p . 'settings' => array('COLUMNS' => array('scope' => array('VCHAR:8', ''), 'setting_name' => array('VCHAR:191', ''),
				'setting_value' => array('TEXT_UNI', '')), 'PRIMARY_KEY' => array('scope','setting_name')),
		));
	}

	public function revert_schema()
	{
		return array('drop_tables' => array_keys($this->update_schema()['add_tables']));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_gallery_media_key', bin2hex(random_bytes(32)))),
			array('permission.add', array('a_plus_gallery')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL','a_plus_gallery')),
			array('permission.permission_set', array('ROLE_ADMIN_STANDARD','a_plus_gallery')));
	}
}
