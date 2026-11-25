<?php
namespace phpbb2plus\downloads\migrations;

class v102_submissions extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v101_feedback'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_dl_';
		return array('add_columns' => array($p . 'files' => array('review_revision' => array('UINT', 0))),
			'add_tables' => array($p . 'media_queue' => array('COLUMNS' => array(
				'job_id' => array('UINT:10', null, 'auto_increment'), 'storage_name' => array('VCHAR:80', ''), 'queued_at' => array('TIMESTAMP', 0)),
				'PRIMARY_KEY' => 'job_id', 'KEYS' => array('dl_media_age' => array('INDEX', 'queued_at')))));
	}

	public function update_data() { return array(array('config.add', array('plus_dl_cleanup_time', 0, true))); }

	public function revert_schema()
	{
		$p = $this->table_prefix . 'plus_dl_';
		return array('drop_columns' => array($p . 'files' => array('review_revision')), 'drop_tables' => array($p . 'media_queue'));
	}
}
