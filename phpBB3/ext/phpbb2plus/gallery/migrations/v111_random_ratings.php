<?php
namespace phpbb2plus\gallery\migrations;

class v111_random_ratings extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v110_category_previews'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_album_pics' => array('legacy_uncategorized' => array('BOOL', 0))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_album_pics' => array('legacy_uncategorized')));
	}

	public function update_data() { return array(array('custom', array(array($this, 'protect_previous_imports')))); }

	public function protect_previous_imports($after)
	{
		// Earlier converters combined unassigned pictures and pictures explicitly placed in the owner's root.
		// Their original categories cannot be recovered here. Keep these imports out of the random draw.
		$table = $this->table_prefix . 'plus_album_pics';
		$result = $this->db->sql_query_limit("SELECT p.pic_id,p.storage_name FROM $table p JOIN " . $this->table_prefix . 'plus_album_cats c
			ON c.cat_id=p.cat_id WHERE c.parent_id=0 AND (c.owner_id>0 OR c.source_owner<>0 OR c.legacy_guest_root=1)
			AND p.pic_id>' . (int) $after . ' ORDER BY p.pic_id', 500);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$ids = array();
		$key = (string) $this->config['plus_gallery_media_key'];
		foreach ($rows as $pic)
		{
			$after = (int) $pic['pic_id'];
			if ($key === '' || $pic['storage_name'] === '' || $pic['storage_name'] === 'gallery_' . hash_hmac('sha256', 'picture:' . $after, $key))
			{
				$ids[] = $after;
			}
		}
		if ($ids) { $this->db->sql_query("UPDATE $table SET legacy_uncategorized=1 WHERE " . $this->db->sql_in_set('pic_id', $ids)); }
		return count($rows) === 500 ? $after : true;
	}
}
