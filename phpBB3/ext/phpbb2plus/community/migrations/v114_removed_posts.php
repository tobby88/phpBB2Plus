<?php
namespace phpbb2plus\community\migrations;

class v114_removed_posts extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v113_portal_media'); }
	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'posts'=>array('plus_public_post'=>array('BOOL',0))));
	}
	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'posts'=>array('plus_public_post')));
	}
	public function update_data() { return array(array('custom', array(array($this, 'record_approved')))); }
	public function record_approved()
	{
		// An older native soft deletion does not tell us if the post ever appeared in the topic.
		$this->db->sql_query('UPDATE ' . $this->table_prefix . 'posts SET plus_public_post=1 WHERE post_visibility=' . ITEM_APPROVED);
	}
}
