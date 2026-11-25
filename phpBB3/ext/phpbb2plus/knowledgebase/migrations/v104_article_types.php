<?php
namespace phpbb2plus\knowledgebase\migrations;

class v104_article_types extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\knowledgebase\migrations\v103_article_administration'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_kb_';
		// A 255-character label can exceed VARCHAR(255) after native four-byte character encoding.
		return array('change_columns' => array($p . 'types' => array('title' => array('TEXT_UNI', '')),
			$p . 'cats' => array('title' => array('TEXT_UNI', ''))),
			'add_columns' => array($p . 'types' => array('label_encoded' => array('BOOL', 0)),
				$p . 'cats' => array('label_encoded' => array('BOOL', 0))));
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_kb_types')),
			array('module.add', array('acp', 'ACP_PLUS_KB', array(
				'module_basename' => '\phpbb2plus\knowledgebase\acp\types_module', 'modes' => array('manage')))));
	}

	// Retain the representation and width when rolling back this module. The base migration
	// drops both tables on purge. Re-enabling the module can read every existing label.
}
