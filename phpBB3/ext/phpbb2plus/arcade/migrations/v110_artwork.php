<?php
namespace phpbb2plus\arcade\migrations;

class v110_artwork extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v109_tournament_notices'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_arc_games' => array(
			'icon_storage' => array('VCHAR:80', ''), 'icon_mime' => array('VCHAR:32', ''), 'icon_bytes' => array('BINT', 0))));
	}

	public function update_data() { return array(array('custom', array(array($this, 'recover')))); }

	public function recover($after = 0)
	{
		global $phpbb_container;
		$artwork = new \phpbb2plus\arcade\service\artwork($this->db, $this->config, $this->table_prefix . 'plus_arc_',
			$this->phpbb_root_path, $phpbb_container->get('filesystem'));
		$batch = $artwork->batch((int) $after,null,false);
		return $batch['after'] ?: true;
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_arc_games' => array('icon_storage','icon_mime','icon_bytes')));
	}
}
