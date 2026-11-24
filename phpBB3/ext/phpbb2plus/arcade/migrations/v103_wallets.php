<?php
namespace phpbb2plus\arcade\migrations;

class v103_wallets extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\arcade\migrations\v102_results'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		return array('add_tables' => array(
			$p . 'currencies' => array('COLUMNS' => array('currency_field' => array('VCHAR:64', ''), 'currency_name' => array('VCHAR_UNI', ''),
				'fraction_digits' => array('USINT', 0), 'initial_amount' => array('VCHAR:255', '0'), 'is_active' => array('BOOL', 0)), 'PRIMARY_KEY' => 'currency_field'),
			$p . 'wallets' => array('COLUMNS' => array('owner_id' => array('UINT', 0), 'currency_field' => array('VCHAR:64', ''),
				'balance_text' => array('VCHAR:255', '0')), 'PRIMARY_KEY' => array('owner_id','currency_field'))));
	}

	public function revert_schema() { return array('drop_tables' => array_keys($this->update_schema()['add_tables'])); }
}
