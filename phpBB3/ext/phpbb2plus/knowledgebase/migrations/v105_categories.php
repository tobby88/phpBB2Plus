<?php
namespace phpbb2plus\knowledgebase\migrations;

class v105_categories extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\knowledgebase\migrations\v104_article_types'); }
	public function update_data()
	{
		return array(array('permission.add', array('a_plus_kb_categories')),
			array('module.add', array('acp', 'ACP_PLUS_KB', array('module_basename' => '\phpbb2plus\knowledgebase\acp\categories_module', 'modes' => array('manage')))));
	}
}
