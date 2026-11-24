<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\profile\contacts;

class v103_contacts extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\community\migrations\v102_shoutbox'); }

	private function columns()
	{
		$columns = array();
		foreach (contacts::FIELDS as $ident => $definition)
		{
			if (strpos($ident, 'plus_') === 0) { $columns['pf_' . $ident] = array('VCHAR_UNI', ''); }
		}
		return $columns;
	}

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'profile_fields_data' => $this->columns()));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'profile_fields_data' => array_keys($this->columns())));
	}

	public function update_data()
	{
		return array(array('custom', array(array($this, 'add_contacts'))));
	}

	public function add_contacts()
	{
		$result = $this->db->sql_query('SELECT lang_id FROM ' . LANG_TABLE);
		$languages = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$result = $this->db->sql_query('SELECT MAX(field_order) AS last_field FROM ' . PROFILE_FIELDS_TABLE);
		$order = (int) $this->db->sql_fetchfield('last_field');
		$this->db->sql_freeresult($result);
		foreach (contacts::FIELDS as $ident => $definition)
		{
			if (strpos($ident, 'plus_') !== 0) { continue; }
			$result = $this->db->sql_query("SELECT field_id FROM " . PROFILE_FIELDS_TABLE . " WHERE field_ident='" . $this->db->sql_escape($ident) . "'");
			$id = (int) $this->db->sql_fetchfield('field_id');
			$this->db->sql_freeresult($result);
			if (!$id)
			{
				$data = array('field_name' => $ident, 'field_ident' => $ident, 'field_type' => 'profilefields.type.string',
					'field_length' => '40', 'field_minlen' => '0', 'field_maxlen' => '255', 'field_validation' => '.*',
					'field_default_value' => '', 'field_novalue' => '', 'field_active' => 1,
					'field_show_profile' => 1, 'field_show_on_vt' => 1, 'field_show_on_pm' => 1, 'field_order' => ++$order,
					'field_is_contact' => 1, 'field_contact_desc' => $definition[1], 'field_contact_url' => '');
				$this->db->sql_query('INSERT INTO ' . PROFILE_FIELDS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $data));
				$id = (int) $this->db->sql_nextid();
			}
			foreach ($languages as $language)
			{
				$lang_id = (int) $language['lang_id'];
				$result = $this->db->sql_query('SELECT field_id FROM ' . PROFILE_LANG_TABLE . " WHERE field_id=$id AND lang_id=$lang_id");
				$exists = $this->db->sql_fetchfield('field_id');
				$this->db->sql_freeresult($result);
				if (!$exists)
				{
					$this->db->sql_query('INSERT INTO ' . PROFILE_LANG_TABLE . ' ' . $this->db->sql_build_array('INSERT',
						array('field_id' => $id, 'lang_id' => $lang_id, 'lang_name' => $definition[1], 'lang_explain' => '', 'lang_default_value' => '')));
				}
			}
		}
	}

	public function revert_data()
	{
		return array(array('custom', array(array($this, 'remove_contacts'))));
	}

	public function remove_contacts()
	{
		foreach (contacts::FIELDS as $ident => $definition)
		{
			if (strpos($ident, 'plus_') !== 0) { continue; }
			$result = $this->db->sql_query("SELECT field_id FROM " . PROFILE_FIELDS_TABLE . " WHERE field_ident='" . $this->db->sql_escape($ident) . "'");
			$id = (int) $this->db->sql_fetchfield('field_id');
			$this->db->sql_freeresult($result);
			if ($id)
			{
				$this->db->sql_query('DELETE FROM ' . PROFILE_LANG_TABLE . ' WHERE field_id=' . $id);
				$this->db->sql_query('DELETE FROM ' . PROFILE_FIELDS_LANG_TABLE . ' WHERE field_id=' . $id);
				$this->db->sql_query('DELETE FROM ' . PROFILE_FIELDS_TABLE . ' WHERE field_id=' . $id);
			}
		}
	}
}
