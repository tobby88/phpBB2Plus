<?php
namespace phpbb2plus\community\migrations;

class v100_style extends \phpbb\db\migration\migration
{
	static public function depends_on()
	{
		return array('\phpbb\db\migration\data\v33x\v3319');
	}

	public function update_data()
	{
		return array(array('custom', array(array($this, 'install_style'))));
	}

	public function install_style()
	{
		$table = $this->table_prefix . 'styles';
		$result = $this->db->sql_query("SELECT style_id, bbcode_bitfield FROM $table WHERE style_path = 'prosilver'");
		$parent = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$parent)
		{
			throw new \RuntimeException('FI Subsilver Shadow requires its installed parent style prosilver.');
		}
		$result = $this->db->sql_query("SELECT style_id FROM $table WHERE style_path = 'fisubsilversh'");
		$id = (int) $this->db->sql_fetchfield('style_id');
		$this->db->sql_freeresult($result);
		if (!$id)
		{
			$style = parse_cfg_file($this->phpbb_root_path . 'styles/fisubsilversh/style.cfg');
			$this->db->sql_query('INSERT INTO ' . $table . ' ' . $this->db->sql_build_array('INSERT', array(
				'style_name' => $style['name'], 'style_copyright' => $style['copyright'],
				'style_path' => 'fisubsilversh', 'style_active' => 1, 'bbcode_bitfield' => $parent['bbcode_bitfield'],
				'style_parent_id' => (int) $parent['style_id'], 'style_parent_tree' => 'prosilver',
			)));
			$id = (int) $this->db->sql_nextid();
		}
		// During a fresh CLI installation the container may have loaded config
		// before the default data was inserted. Read the persisted setting.
		$result = $this->db->sql_query("SELECT config_value FROM " . $this->table_prefix . "config WHERE config_name='default_style'");
		$current = (int) $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);
		if ($current === (int) $parent['style_id'])
		{
			$this->config->set('default_style', $id);
			$this->db->sql_query('UPDATE ' . $this->table_prefix . 'users SET user_style = ' . $id
				. ' WHERE user_id = ' . ANONYMOUS . ' AND user_style IN (0, ' . (int) $parent['style_id'] . ')');
		}
	}
}
