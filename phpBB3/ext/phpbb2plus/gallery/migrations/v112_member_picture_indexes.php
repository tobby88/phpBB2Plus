<?php
namespace phpbb2plus\gallery\migrations;

class v112_member_picture_indexes extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v111_random_ratings'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_album_';
		return array('add_index' => array($p . 'pics' => array('author_time' => array('author_id','created_at','pic_id')),
			$p . 'comments' => array('author_pic' => array('author_id','pic_id')), $p . 'ratings' => array('voter_pic' => array('user_id','pic_id'))));
	}

	public function revert_schema()
	{
		$p = $this->table_prefix . 'plus_album_';
		return array('drop_keys' => array($p . 'pics' => array('author_time'), $p . 'comments' => array('author_pic'), $p . 'ratings' => array('voter_pic')));
	}
}
