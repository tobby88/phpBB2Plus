<?php
namespace phpbb2plus\knowledgebase\migrations;

class v103_article_administration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\knowledgebase\migrations\v102_delivery'); }

	public function update_data()
	{
		return array(
			array('permission.add', array('a_plus_kb_articles')),
			array('module.add', array('acp', 'ACP_CAT_DOT_MODS', 'ACP_PLUS_KB')),
			array('module.add', array('acp', 'ACP_PLUS_KB', array(
				'module_basename' => '\phpbb2plus\knowledgebase\acp\articles_module', 'modes' => array('manage')))),
		);
	}
}
