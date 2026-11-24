<?php
if (!defined('IN_PHPBB')) { exit; }

/** PHP2 derived a physical column from the display name until an explicit mapping was introduced. */
function plus_custom_source_column($row)
{
	if ($row['field_column'] !== null) { return $row['field_column']; }
	$text = mb_convert_encoding($row['field_name'],'ISO-8859-1','UTF-8');
	$text = str_replace(array('&quot;','&amp;','&lt;','&gt;'),array('"','&','<','>'),$text);
	$text = preg_replace("#[\s\*\$\(\)!\.,\-\?\/\\\[\]\{\};\:'´\x60\"&\^+=<>\|]#",'_',$text);
	return strtolower($text);
}

function plus_custom_profile_fields()
{
	global $src_db, $convert;
	static $fields = null;
	if ($fields !== null) { return $fields; }
	$p = $convert->src_table_prefix;
	$result = $src_db->sql_query("SELECT * FROM {$p}profile_fields ORDER BY field_id");
	$definitions = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$fields = array();
	if (!$definitions) { return $fields; }
	$table = $src_db->sql_escape($p . 'users');
	$result = $src_db->sql_query("SELECT COLUMN_NAME,DATA_TYPE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'");
	$physical = array();
	while ($r = $src_db->sql_fetchrow($result))
	{
		if (in_array($r['DATA_TYPE'],array('char','varchar','tinytext','text','mediumtext','longtext'),true) && !preg_match('/generated/i',$r['EXTRA']))
		{
			$physical[$r['COLUMN_NAME']] = true;
		}
	}
	$src_db->sql_freeresult($result);
	$excluded = array_flip(plus_core_account_columns());
	$defaults = array('text_field_default','text_area_default','radio_button_default','checkbox_default');
	foreach ($definitions as $f)
	{
		$id = (int) $f['field_id'];
		$type = (int) $f['field_type'];
		$column = plus_custom_source_column($f);
		if ($id < 1 || !isset($defaults[$type]) || !preg_match('/^[a-z_][a-z0-9_]{0,63}$/D',$column) ||
			!isset($physical[$column]) || isset($excluded[$column]))
		{
			throw new \RuntimeException("Check the type and physical source column of custom profile field $id before converting.");
		}
		$excluded[$column] = true;
		$f['source_column'] = $column;
		$f['ident'] = 'plus_f' . $id;
		$f['choices'] = array();
		if ($type >= 2)
		{
			$next = $type === 2 ? 2 : 1; // Native dropdown option one is the empty choice.
			foreach (explode(',',(string) $f[$type === 2 ? 'radio_button_values' : 'checkbox_values']) as $choice)
			{
				if ($choice !== '' && !isset($f['choices'][$choice])) { $f['choices'][$choice] = $next++; }
			}
		}
		$f['native_default'] = plus_custom_profile_value($f,(string) $f[$defaults[$type]]);
		$fields[$id] = $f;
	}
	return $fields;
}

function plus_custom_profile_value($field, $value)
{
	$type = (int) $field['field_type'];
	if ($type < 2) { return plus_convert_byline($value); }
	if ($value === null || $value === '') { return $type === 2 ? 1 : ''; }
	$ids = array();
	foreach ($type === 2 ? array($value) : explode(',',$value) as $label)
	{
		if ($label === '') { continue; }
		if (!isset($field['choices'][$label]))
		{
			$id = (int) $field['field_id'];
			throw new \RuntimeException("Custom profile field $id contains a choice missing from its definition. Restore the option in the offline source copy and retry.");
		}
		$ids[] = $field['choices'][$label];
	}
	return $type === 2 ? $ids[0] : implode(',',array_unique($ids));
}

function plus_custom_account_value($value, $id)
{
	$fields = plus_custom_profile_fields();
	return plus_custom_profile_value($fields[(int) $id],$value);
}

function plus_define_custom_profiles()
{
	global $db, $phpbb_container;
	$fields = plus_custom_profile_fields();
	if (!$fields) { return; }
	$tools = $phpbb_container->get('dbal.tools');
	$result = $db->sql_query('SELECT lang_id,lang_iso FROM ' . LANG_TABLE . ' ORDER BY lang_id');
	$languages = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	$result = $db->sql_query('SELECT MAX(field_order) AS last_field FROM ' . PROFILE_FIELDS_TABLE);
	$order = (int) $db->sql_fetchfield('last_field');
	$db->sql_freeresult($result);
	// Long defaults and encoded Unicode labels can exceed native 255-character metadata.
	// Widen only the affected metadata columns, before opening any data transaction.
	$wide = array();
	foreach ($fields as $f)
	{
		if (utf8_strlen((string) $f['native_default']) > 255)
		{
			$wide[PROFILE_LANG_TABLE]['lang_default_value'] = true;
			$wide[PROFILE_FIELDS_TABLE]['field_default_value'] = true;
		}
		if (utf8_strlen(plus_convert_byline($f['field_name'])) > 255) { $wide[PROFILE_LANG_TABLE]['lang_name'] = true; }
		foreach ($f['choices'] as $label=>$index)
		{
			if (utf8_strlen(plus_convert_byline($label)) > 255) { $wide[PROFILE_FIELDS_LANG_TABLE]['lang_value'] = true; break; }
		}
	}
	foreach ($wide as $table=>$columns)
	{
		foreach (array_keys($columns) as $column) { $tools->sql_column_change($table,$column,array('MTEXT_UNI','')); }
	}
	$types = array('profilefields.type.string','profilefields.type.text','profilefields.type.dropdown','profilefields.type.plus_multi');
	foreach ($fields as $id=>$f)
	{
		$ident = $f['ident'];
		$column = 'pf_' . $ident;
		$type = (int) $f['field_type'];
		$result = $db->sql_query('SELECT field_id,plus_source_id,field_type FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_ident='$ident'");
		$prior = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		$exists = $tools->sql_column_exists(PROFILE_FIELDS_DATA_TABLE,$column);
		if (($prior && ((int) $prior['plus_source_id'] !== $id || $prior['field_type'] !== $types[$type])) || (!$prior && $exists))
		{
			throw new \RuntimeException("The destination profile identifier $ident already has a different owner.");
		}
		$editable = (int) (bool) $f['users_can_view'];
		$visible = $editable && ($f['view_in_profile'] || $f['view_in_topic'] || $f['view_in_memberlist']);
		$data = array('field_ident'=>$ident,'field_name'=>$ident,'field_type'=>$types[$type], 'field_active'=>1,
			'field_hide'=>1-$editable, 'field_no_view'=>(int) !$visible, 'field_required'=>(int) ($editable && $f['is_required']),
			'field_show_profile'=>$editable,'field_show_on_reg'=>$editable,'field_show_on_vt'=>(int) ($editable && $f['view_in_topic']),
			'field_show_on_ml'=>(int) ($editable && $f['view_in_memberlist']),'field_show_on_pm'=>0,'field_is_contact'=>0,
			'plus_source_id'=>$id,'plus_view_profile'=>(int) ($editable && $f['view_in_profile']),
			'plus_profile_position'=>(int) $f['profile_location'],'plus_topic_position'=>(int) $f['topic_location'],
			'field_length'=>$type === 1 ? '5|60' : ($type === 0 ? '40' : '0'), 'field_minlen'=>'0',
			'field_maxlen'=>$type < 2 ? (string) $f[$type === 0 ? 'text_field_maxlen' : 'text_area_maxlen'] : (string) count($f['choices']),
			'field_validation'=>$type < 2 ? '.*' : '', 'field_novalue'=>$type === 2 ? '1' : '', 'field_default_value'=>(string) $f['native_default']);
		$db->sql_transaction('begin');
		try
		{
			if ($prior)
			{
				$native = (int) $prior['field_id'];
				$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',$data) . " WHERE field_id=$native");
			}
			else
			{
				$data['field_order'] = ++$order;
				$db->sql_query('INSERT INTO ' . PROFILE_FIELDS_TABLE . ' ' . $db->sql_build_array('INSERT',$data));
				$native = (int) $db->sql_nextid();
			}
			$db->sql_query('DELETE FROM ' . PROFILE_LANG_TABLE . " WHERE field_id=$native");
			$db->sql_query('DELETE FROM ' . PROFILE_FIELDS_LANG_TABLE . " WHERE field_id=$native");
			foreach ($languages as $lang)
			{
				$language = (int) $lang['lang_id'];
				$translation = array('field_id'=>$native, 'lang_id'=>$language, 'lang_name'=>plus_convert_byline($f['field_name']),
					'lang_explain'=>plus_convert_byline($f['field_description']), 'lang_default_value'=>$type === 2 ? '' : $f['native_default']);
				$db->sql_query('INSERT INTO ' . PROFILE_LANG_TABLE . ' ' . $db->sql_build_array('INSERT',$translation));
				$options = array();
				if ($type === 2) { $options[0] = strpos($lang['lang_iso'],'de') === 0 ? 'Keine Angabe' : 'Unspecified'; }
				foreach ($f['choices'] as $label=>$index) { $options[$index-1] = plus_convert_byline($label); }
				$rows = array();
				foreach ($options as $index=>$label)
				{
					$rows[] = array('field_id'=>$native,'lang_id'=>$language,'option_id'=>$index,'field_type'=>$types[$type],'lang_value'=>$label);
				}
				foreach (array_chunk($rows,100) as $batch) { $db->sql_multi_insert(PROFILE_FIELDS_LANG_TABLE,$batch); }
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
		// Create after recording ownership. A retry can finish a previously interrupted DDL.
		if (!$exists) { $tools->sql_column_add(PROFILE_FIELDS_DATA_TABLE,$column,$type === 2 ? array('UINT',1) : array('MTEXT_UNI','')); }
	}
}
