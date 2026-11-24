<?php
namespace phpbb2plus\gallery\migrations;

class v101_publication extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\gallery\migrations\v100_gallery'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_album_';
		return array('add_tables' => array(
			$p . 'writes' => array('COLUMNS' => array('lock_id' => array('USINT', 0)), 'PRIMARY_KEY' => 'lock_id'),
			$p . 'batches' => array('COLUMNS' => array('batch_key' => array('CHAR:64', ''), 'payload_hash' => array('CHAR:64', ''),
				'created_at' => array('TIMESTAMP', 0)), 'PRIMARY_KEY' => 'batch_key', 'KEYS' => array('age' => array('INDEX', 'created_at'))),
		));
	}

	public function update_data()
	{
		return array(array('custom', array(array($this, 'seed_lock'))));
	}

	public function seed_lock()
	{
		$table = $this->table_prefix . 'plus_album_writes';
		$result = $this->db->sql_query("SELECT lock_id FROM $table WHERE lock_id=1");
		$exists = $this->db->sql_fetchfield('lock_id');
		$this->db->sql_freeresult($result);
		if ($exists === false) { $this->db->sql_query("INSERT INTO $table (lock_id) VALUES (1)"); }
	}

	public function revert_schema()
	{
		return array('drop_tables' => array_keys($this->update_schema()['add_tables']));
	}
}
