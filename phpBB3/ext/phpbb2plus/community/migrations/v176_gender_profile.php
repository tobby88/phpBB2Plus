<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\profile\gender;

class v176_gender_profile extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v108_custom_profiles'); }
	public function update_data() { return array(array('custom', array(array($this, 'install_field')))); }

	private function first($sql)
	{
		$r = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($r);
		$this->db->sql_freeresult($r);
		return $row;
	}

	public function install_field()
	{
		$owner = gender::owner($this->config);
		if ($owner)
		{
			list($id, $ident) = $owner;
			// A native administrator may have edited or removed the field since installation.
			if (!$this->first('SELECT field_id FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_id=$id AND field_ident='$ident' AND field_type='" . gender::TYPE . "'")) { return; }
		}
		else
		{
			$ident = 'plus_gender';
			for ($n = 2; $this->first('SELECT field_id FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_ident='$ident'")
				|| $this->db_tools->sql_column_exists(PROFILE_FIELDS_DATA_TABLE, 'pf_' . $ident); ++$n)
			{ $ident = 'plus_gender_' . $n; }
			$this->db->sql_transaction('begin');
			try
			{
				$order = (int) $this->first('SELECT MAX(field_order) AS last_field FROM ' . PROFILE_FIELDS_TABLE)['last_field'] + 1;
				$definition = array('field_name'=>$ident,'field_ident'=>$ident,'field_type'=>gender::TYPE,'field_length'=>'0',
					'field_minlen'=>'0','field_maxlen'=>'0','field_validation'=>'','field_novalue'=>'1','field_default_value'=>'1',
					'field_required'=>0,'field_active'=>1,'field_hide'=>0,'field_no_view'=>0,'field_show_profile'=>1,'field_show_on_reg'=>1,
					'field_show_on_vt'=>1,'field_show_on_ml'=>0,'field_show_on_pm'=>0,'field_is_contact'=>0,'field_show_novalue'=>1,'field_order'=>$order);
				$this->db->sql_query('INSERT INTO ' . PROFILE_FIELDS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $definition));
				$id = (int) $this->db->sql_nextid();
				$r = $this->db->sql_query('SELECT lang_id,lang_iso FROM ' . LANG_TABLE);
				$languages = $this->db->sql_fetchrowset($r);
				$this->db->sql_freeresult($r);
				foreach ($languages as $language)
				{
					$labels = gender::labels($language['lang_iso']);
					$name = array_shift($labels);
					$identity = array('field_id'=>$id,'lang_id'=>(int) $language['lang_id']);
					$this->db->sql_query('INSERT INTO ' . PROFILE_LANG_TABLE . ' ' . $this->db->sql_build_array('INSERT',
						$identity + array('lang_name'=>$name,'lang_explain'=>'','lang_default_value'=>'')));
					$options = array();
					foreach ($labels as $option=>$label) { $options[] = $identity + array('option_id'=>$option,'field_type'=>gender::TYPE,'lang_value'=>$label); }
					$this->db->sql_multi_insert(PROFILE_FIELDS_LANG_TABLE, $options);
				}
				$this->config->set(gender::SETTING, $id . ':' . $ident);
				$this->db->sql_transaction('commit');
			}
			catch (\Throwable $error) { $this->db->sql_transaction('rollback'); throw $error; }
		}
		// MySQL commits DDL implicitly. Recording ownership first makes a retry complete the same field.
		if (!$this->db_tools->sql_column_exists(PROFILE_FIELDS_DATA_TABLE, 'pf_' . $ident))
		{ $this->db_tools->sql_column_add(PROFILE_FIELDS_DATA_TABLE, 'pf_' . $ident, array('UINT', 1)); }
	}
	// The native dropdown, its edited labels and members' choices stay usable after extension removal.
}
