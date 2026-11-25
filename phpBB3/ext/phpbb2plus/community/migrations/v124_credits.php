<?php
namespace phpbb2plus\community\migrations;

class v124_credits extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v110_portal'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_credits' => array(
			'COLUMNS' => array(
				'credit_id' => array('UINT', null, 'auto_increment'), 'visible' => array('BOOL', 0),
				'title' => array('TEXT_UNI', ''), 'description' => array('TEXT_UNI', ''), 'author' => array('TEXT_UNI', ''),
				'contact_email' => array('TEXT_UNI', ''), 'author_website' => array('TEXT_UNI', ''), 'version_text' => array('TEXT_UNI', ''),
				'download_url' => array('MTEXT_UNI', ''), 'source_file' => array('TEXT_UNI', ''),
				'file_mtime' => array('TIMESTAMP', 0), 'added_time' => array('TIMESTAMP', 0),
			),
			'PRIMARY_KEY' => 'credit_id', 'KEYS' => array('visible' => array('INDEX', 'visible')),
		)));
	}

	public function revert_schema() { return array('drop_tables' => array($this->table_prefix . 'plus_credits')); }
}
