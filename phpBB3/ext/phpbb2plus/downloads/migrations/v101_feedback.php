<?php
namespace phpbb2plus\downloads\migrations;

class v101_feedback extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\downloads\migrations\v100_library'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_dl_receipts' => array('COLUMNS' => array(
			'request_key'=>array('CHAR:64',''), 'payload_hash'=>array('CHAR:64',''), 'file_id'=>array('UINT:10',0),
			'item_id'=>array('UINT:10',0), 'created_at'=>array('TIMESTAMP',0)), 'PRIMARY_KEY'=>'request_key',
			'KEYS'=>array('dl_receipt_file'=>array('INDEX','file_id')))));
	}

	public function revert_schema() { return array('drop_tables' => array_keys($this->update_schema()['add_tables'])); }
}
