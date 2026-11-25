<?php
namespace phpbb2plus\community\migrations;

class v129_unowned_attachments extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v104_media'); }

	public function update_data()
	{
		return array(array('custom', array(array($this, 'clear_guest_owner'))));
	}

	public function clear_guest_owner()
	{
		$files = $this->table_prefix . 'attachments';
		$references = $this->table_prefix . 'plus_attach_refs';
		$this->db->sql_query("UPDATE $files SET poster_id=0 WHERE is_orphan=1 AND post_msg_id=0 AND in_message=0
			AND poster_id=" . ANONYMOUS . ' AND physical_filename ' . $this->db->sql_like_expression('plus_' . $this->db->get_any_char()) . "
			AND attach_id IN (SELECT attach_id FROM $references WHERE parent_id=0 AND in_message=0)");
	}
}
