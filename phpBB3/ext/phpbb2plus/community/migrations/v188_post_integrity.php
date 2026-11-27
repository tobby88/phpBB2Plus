<?php
namespace phpbb2plus\community\migrations;

class v188_post_integrity extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v132_removed_topics', '\phpbb2plus\community\migrations\v149_structure_review');
	}
	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_CAT_DATABASE', array(
			'module_basename'=>'\phpbb2plus\community\acp\post_references_module', 'modes'=>array('check')))));
	}
}
