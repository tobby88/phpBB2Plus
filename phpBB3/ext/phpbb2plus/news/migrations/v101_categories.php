<?php
namespace phpbb2plus\news\migrations;

class v101_categories extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\news\migrations\v100_news'); }
	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_news_images'=>array(
			'COLUMNS'=>array('asset'=>array('VCHAR:80',''), 'label'=>array('VCHAR_UNI','')),
			'PRIMARY_KEY'=>'asset')));
	}
	public function revert_schema() { return array('drop_tables'=>array($this->table_prefix . 'plus_news_images')); }
	public function update_data()
	{
		return array(array('module.add',array('acp','ACP_MESSAGES',array(
			'module_basename'=>'\phpbb2plus\news\acp\categories_module','modes'=>array('manage')))));
	}
}
