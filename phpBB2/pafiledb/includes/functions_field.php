<?php
/***************************************************************************
 *                                functions_field.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: index.php,v 1.99.2.1 2002/12/19 17:17:40 psotfx Exp $
 *
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

 
// PLEASE DON'T TAKE THIS CLASS AND USE IT, I WILL KEEP MY EYES ON IT
// I KNOW SOME PEOPLE MAY TAKE IT AND USE IT TO DO CUSTOM FIELD FOR PROFILE
// BUT I AM PLANNING TO MAKE THIS FEATURE VERY SOON 


class custom_field
{
	var $field_rowset = array();
	var $field_data_rowset = array();
	
	//===================================================
	//prepare data
	//===================================================
	function init($file_id = null)
	{
		global $db;
		$this->field_rowset = array(); $this->field_data_rowset = array();
		if ($file_id !== null && (!is_int($file_id) || $file_id < 0 || $file_id > 2147483647)) { $this->invalid_file_data(); }
		
		$sql = "SELECT * 
			FROM " . PA_CUSTOM_TABLE . "
			ORDER BY field_order ASC";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt Query Custom field', '', __LINE__, __FILE__, $sql);
		}

		while($row = $db->sql_fetchrow($result))
		{
			$this->field_rowset[$row['custom_id']] = $row;
		}
		unset($row);
		$db->sql_freeresult($result);
		
		$sql = "SELECT * 
			FROM " . PA_CUSTOM_DATA_TABLE . ($file_id === null ? '' : ' WHERE customdata_file=' . $file_id);

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt Query Custom field', '', __LINE__, __FILE__, $sql);
		}

		while($row = $db->sql_fetchrow($result))
		{
			$this->field_data_rowset[$row['customdata_file']][$row['customdata_custom']] = $row;
		}

		unset($row);
		
		$db->sql_freeresult($result);
	}
	
	//===================================================
	// check if there is a data in the database
	//===================================================
	function field_data_exist()
	{
		if(!empty($this->field_data_rowset))
		{
			return true;
		}
		return false;
	}
	
	function field_exist()
	{
		if(!empty($this->field_rowset))
		{
			return true;
		}
		return false;
	}
	
	//===================================================
	// display data in the file page
	//===================================================
	
	function display_data($file_id)
	{
		global $pafiledb_template;
		if($this->field_data_exist())
		{
			if(isset($this->field_data_rowset[$file_id]))
			{
				foreach($this->field_data_rowset[$file_id] as $field_id => $data)
				{
					if (!isset($this->field_rowset[$field_id])) { continue; }
					if(isset($data['data']) && $data['data'] !== '')
					{
						switch($this->field_rowset[$field_id]['field_type'])
						{
							case PA_FIELD_INPUT:
							case PA_FIELD_TEXTAREA:
							case PA_FIELD_RADIO:
							case PA_FIELD_SELECT:
								$field_data = $data['data'];
								break;
							case PA_FIELD_SELECT_MULTIPLE:
							case PA_FIELD_CHECKBOX:
								$field_data = htmlspecialchars(implode(', ', phpbb_safe_unserialize_scalar_array($data['data'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
								break;
							default:
							continue 2;
						}
					
						$pafiledb_template->assign_block_vars('custom_field', array(
							'CUSTOM_NAME' => $this->field_rowset[$field_id]['custom_name'],
							'DATA' => $field_data) 
						);
					}
				}
			}
			else
			{
				return false;
			}
		}
		else
		{
			return false;
		}
	}
	
	//===================================================
	// display custom field and data in the add/edit page
	//===================================================
	
	function display_edit($file_id = false)
	{
		$return = false;
		if($this->field_exist())
		{
			foreach($this->field_rowset as $field_id => $field_data)
			{
				switch($field_data['field_type'])
				{
					case PA_FIELD_INPUT:
						$this->display_edit_input($file_id, $field_id, $field_data);
						break;
					case PA_FIELD_TEXTAREA:
						$this->display_edit_textarea($file_id, $field_id, $field_data);
						break;
					case PA_FIELD_RADIO:
						$this->display_edit_radio($file_id, $field_id, $field_data);
						break;
					case PA_FIELD_SELECT:
						$this->display_edit_select($file_id, $field_id, $field_data);
						break;
					case PA_FIELD_SELECT_MULTIPLE:
						$this->display_edit_select_multiple($file_id, $field_id, $field_data);
						break;
					case PA_FIELD_CHECKBOX:
						$this->display_edit_checkbox($file_id, $field_id, $field_data);
						break;
				}

				$return = TRUE;
			}
		}
		return $return;
	}
	
	function display_edit_input($file_id, $field_id, $field_data)
	{
		global $pafiledb_template;
		$pafiledb_template->assign_block_vars('input', array(
			'FIELD_NAME' => $field_data['custom_name'],
			'FIELD_ID' => $field_data['custom_id'],
			'FIELD_DESCRIPTION' => $field_data['custom_description'],
			'FIELD_VALUE' => isset($this->field_data_rowset[$file_id][$field_id]['data']) ? $this->field_data_rowset[$file_id][$field_id]['data'] : '')
		);
	}

	function display_edit_textarea($file_id, $field_id, $field_data)
	{
		global $pafiledb_template;
		$pafiledb_template->assign_block_vars('textarea', array(
			'FIELD_NAME' => $field_data['custom_name'],
			'FIELD_ID' => $field_data['custom_id'],
			'FIELD_DESCRIPTION' => $field_data['custom_description'],
			'FIELD_VALUE' => isset($this->field_data_rowset[$file_id][$field_id]['data']) ? $this->field_data_rowset[$file_id][$field_id]['data'] : '')
		);
	}
	
	function display_edit_radio($file_id, $field_id, $field_data)
	{
		global $pafiledb_template;
		$pafiledb_template->assign_block_vars('radio', array(
			'FIELD_NAME' => $field_data['custom_name'],
			'FIELD_ID' => $field_data['custom_id'],
			'FIELD_DESCRIPTION' => $field_data['custom_description'])
		);
		
		$data = isset($this->field_data_rowset[$file_id][$field_id]['data']) ? $this->field_data_rowset[$file_id][$field_id]['data'] : null;
		$field_datas = (!empty($field_data['data'])) ? phpbb_safe_unserialize_scalar_array($field_data['data']) : array();

		if(!empty($field_datas))
		{
			foreach($field_datas as $key => $value)
			{
				$pafiledb_template->assign_block_vars('radio.row', array(
					'FIELD_VALUE' => $value,
					'FIELD_SELECTED' => ($data == $value ) ? ' checked="checked"' : '')
				);
			}
		}
	}
	
	function display_edit_select($file_id, $field_id, $field_data)
	{
		global $pafiledb_template;
		$pafiledb_template->assign_block_vars('select', array(
			'FIELD_NAME' => $field_data['custom_name'],
			'FIELD_ID' => $field_data['custom_id'],
			'FIELD_DESCRIPTION' => $field_data['custom_description'])
		);
		
		$data = isset($this->field_data_rowset[$file_id][$field_id]['data']) ? $this->field_data_rowset[$file_id][$field_id]['data'] : null;
		$field_datas = (!empty($field_data['data'])) ? phpbb_safe_unserialize_scalar_array($field_data['data']) : array();

		if(!empty($field_datas))
		{
			foreach($field_datas as $key => $value)
			{
				$pafiledb_template->assign_block_vars('select.row', array(
					'FIELD_VALUE' => $value,
					'FIELD_SELECTED' => ($data == $value) ? ' selected="selected"' : '')
				);
			}
		}
	}
	
	function display_edit_select_multiple($file_id, $field_id, $field_data)
	{
		global $pafiledb_template;
		$pafiledb_template->assign_block_vars('select_multiple', array(
			'FIELD_NAME' => $field_data['custom_name'],
			'FIELD_ID' => $field_data['custom_id'],
			'FIELD_DESCRIPTION' => $field_data['custom_description'])
		);
		
		$data = (!empty($this->field_data_rowset[$file_id][$field_id]['data'])) ? phpbb_safe_unserialize_scalar_array($this->field_data_rowset[$file_id][$field_id]['data']) : array();
		$field_datas = (!empty($field_data['data'])) ? phpbb_safe_unserialize_scalar_array($field_data['data']) : array();
		
		if(!empty($field_datas))
		{
			foreach($field_datas as $key => $value)
			{
				$selected = '';
				foreach($data as $field_value)
				{
					if((string)$field_value === html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'))
					{
						$selected = '  selected="selected"';
						break;
					}
				}
				$pafiledb_template->assign_block_vars('select_multiple.row', array(
					'FIELD_VALUE' => $value,
					'FIELD_SELECTED' => $selected)
				);
			}
		}
	}
	
	function display_edit_checkbox($file_id, $field_id, $field_data)
	{
		global $pafiledb_template;
		$pafiledb_template->assign_block_vars('checkbox', array(
			'FIELD_NAME' => $field_data['custom_name'],
			'FIELD_ID' => $field_data['custom_id'],
			'FIELD_DESCRIPTION' => $field_data['custom_description'])
		);
		
		$data = (!empty($this->field_data_rowset[$file_id][$field_id]['data'])) ? phpbb_safe_unserialize_scalar_array($this->field_data_rowset[$file_id][$field_id]['data']) : array();
		$field_datas = (!empty($field_data['data'])) ? phpbb_safe_unserialize_scalar_array($field_data['data']) : array();

		if(!empty($field_datas))
		{
			foreach($field_datas as $key => $value)
			{
				$checked = '';
				foreach($data as $field_value)
				{
					if((string)$field_value === html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'))
					{
						$checked = ' checked';
						break;
					}
				}
				$pafiledb_template->assign_block_vars('checkbox.row', array(
					'FIELD_VALUE' => $value,
					'FIELD_CHECKED' => $checked)
				);
			}
		}
	}
	
	// Compatibility entry points also use the owned writer. The ACP controller
	// sends multi-field deletion as one batch rather than looping this method.
	function update_add_field($field_type, $field_id = false)
	{
		require_once dirname(__FILE__) . '/functions_custom_storage.php';
		$post=$_POST;$post['field_type']=$field_type;$post['field_id']=$field_id===false?0:$field_id;
		return pafiledb_custom_save('save',$post);
	}

	function delete_field($field_id)
	{
		require_once dirname(__FILE__) . '/functions_custom_storage.php';
		$field_id=PaFileDbFileAuthority::id($field_id);
		return pafiledb_custom_save('delete',array('field_ids'=>array($field_id=>'yes')));
	}

	function get_field_data($field_id)
	{
		$return_array = $this->field_rowset[$field_id];
		$return_array['data'] = implode("\n", phpbb_safe_unserialize_scalar_array($return_array['data']));
		return $return_array;
	}
	
	//===================================================
	//file data in custom field operations
	//===================================================
	
	private function invalid_file_data()
	{
		global $lang;
		if (function_exists('pafiledb_file_fail')) { pafiledb_file_fail('PA_file_invalid'); }
		message_die(GENERAL_ERROR, isset($lang['PA_file_invalid']) ? $lang['PA_file_invalid'] : 'Invalid download field data.');
	}

	// Redisplay rejected input without validating it a second time or writing.
	// Definitions remain authoritative; unknown fields and nested values cannot
	// introduce template bindings. Scalars are stored as HTML, choices as raw data.
	function adopt_file_draft($file_id)
	{
		$posted = isset($_POST['field']) && is_array($_POST['field']) ? $_POST['field'] : array();
		foreach ($this->field_rowset as $id => $definition)
		{
			$multiple = in_array((int)$definition['field_type'], array(PA_FIELD_SELECT_MULTIPLE,PA_FIELD_CHECKBOX), true);
			if (!array_key_exists($id, $posted) && !$multiple) { continue; }
			$value = array_key_exists($id, $posted) ? $posted[$id] : array();
			if ($multiple)
			{
				$values = array();
				foreach (is_array($value) ? $value : array() as $item) { if (is_string($item) || is_int($item)) { $values[] = (string)phpbb_request_raw_value($item); } }
				$data = $values ? serialize($values) : '';
			}
			else { $data = is_string($value) || is_int($value) ? htmlspecialchars((string)phpbb_request_raw_value($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; }
			$this->field_data_rowset[$file_id][$id] = array('customdata_file'=>$file_id,'customdata_custom'=>$id,'data'=>$data);
		}
	}

	// Validate every field before the first write. Request escaping and SQL
	// escaping are separate boundaries; serialized values contain raw strings.
	function file_data_values()
	{
		global $_POST;
		if (isset($_POST['field']) && !is_array($_POST['field'])) { $this->invalid_file_data(); }
		$fields = isset($_POST['field']) ? $_POST['field'] : array();
		foreach ($this->field_rowset as $id => $definition)
		{
			if (in_array((int)$definition['field_type'], array(PA_FIELD_SELECT_MULTIPLE, PA_FIELD_CHECKBOX), true) && !array_key_exists($id, $fields)) { $fields[$id] = array(); }
		}
		$values = array();
		foreach ($fields as $field_id => $input)
		{
			if (!(is_int($field_id) || is_string($field_id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$field_id) || (float)$field_id > 2147483647) { $this->invalid_file_data(); }
			$field_id = (int)$field_id; if (!isset($this->field_rowset[$field_id])) { continue; }
			$definition = $this->field_rowset[$field_id]; $type = (int)$definition['field_type'];
			if (!in_array($type, array(PA_FIELD_INPUT,PA_FIELD_TEXTAREA,PA_FIELD_RADIO,PA_FIELD_SELECT,PA_FIELD_SELECT_MULTIPLE,PA_FIELD_CHECKBOX), true)) { $this->invalid_file_data(); }
			$multiple = in_array($type, array(PA_FIELD_SELECT_MULTIPLE,PA_FIELD_CHECKBOX), true);
			if ($multiple ? !is_array($input) : !(is_string($input) || is_int($input))) { $this->invalid_file_data(); }
			$raw = array();
			foreach ($multiple ? array_values($input) : array($input) as $value)
			{
				if (!(is_string($value) || is_int($value))) { $this->invalid_file_data(); }
				$value = (string)phpbb_request_raw_value($value);
				if (strlen($value)>65535 || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) { $this->invalid_file_data(); }
				if (!empty($definition['regex']) && @preg_match('#'.$definition['regex'].'#siU',$value)!==1) { $this->invalid_file_data(); }
				$raw[] = $value;
			}
			$data = $multiple ? ($raw ? serialize($raw) : '') : htmlspecialchars($raw[0], ENT_QUOTES, 'UTF-8');
			if (strlen($data)>65535) { $this->invalid_file_data(); } $values[$field_id] = $data;
		}
		return $values;
	}

	function file_update_data($file_id)
	{
		global $db;
		if (!(is_int($file_id) || is_string($file_id)) || !preg_match('/^[1-9][0-9]*$/D',(string)$file_id) || (float)$file_id>2147483647) { $this->invalid_file_data(); }
		$file_id = (int)$file_id; $values = $this->file_data_values();
		foreach ($values as $field_id => $data)
		{
			$sql = 'DELETE FROM '.PA_CUSTOM_DATA_TABLE.' WHERE customdata_file='.$file_id.' AND customdata_custom='.$field_id;
			if (!$db->sql_query($sql)) { message_die(GENERAL_ERROR, 'Could not delete download custom data'); }
			if ($data !== '')
			{
				$sql = 'INSERT INTO '.PA_CUSTOM_DATA_TABLE." (customdata_file,customdata_custom,data)VALUES(".$file_id.','.$field_id.",'".$db->sql_escape($data)."')";
				if (!$db->sql_query($sql)) { message_die(GENERAL_ERROR, 'Could not save download custom data'); }
			}
		}
		return true;
	}

}
?>
