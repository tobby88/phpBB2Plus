<?php
namespace phpbb2plus\arcade\migrations;

class v102_results extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\arcade\migrations\v101_play'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		return array('add_columns' => array($p . 'scores' => array('guest_key' => array('CHAR:64', ''), 'save_encoded' => array('BOOL', 0)),
				$p . 'saves' => array('save_encoded' => array('BOOL', 0))),
			'change_columns' => array($p . 'scores' => array('player_ip' => array('VCHAR:45', '0')),
				$p . 'all_scores' => array('player_ip' => array('VCHAR:45', '0'))));
	}

	public function revert_schema()
	{
		// New rows can contain IPv6 addresses. Retain enough space for them on downgrade.
		return array('drop_columns' => array($this->table_prefix . 'plus_arc_scores' => array('guest_key','save_encoded'),
			$this->table_prefix . 'plus_arc_saves' => array('save_encoded')));
	}
}
