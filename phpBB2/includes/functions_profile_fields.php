<?php
function get_fields($where_clause = '', $expect_multiple = true, $selection = '*')
{
  global $db;

  $sql = "SELECT $selection FROM " . PROFILE_FIELDS_TABLE . "
    $where_clause
    ORDER BY field_id ASC";
  if(!($result = $db->sql_query($sql)))
    message_die(GENERAL_ERROR,'Could not select from ' . PROFILE_FIELDS_TABLE,'',__LINE__,__FILE__,$sql);

  if($expect_multiple)
  {
    $profile_data = array();
    while($temp = $db->sql_fetchrow($result))
      if(!empty($temp))
        $profile_data[] = $temp;
  }
  else
    $profile_data = $db->sql_fetchrow($result);

  return $profile_data;
}

function text_to_column($text)
{
  if (function_exists('mb_convert_encoding'))
  {
    $text = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
  }
  else if (function_exists('iconv'))
  {
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    $text = ($converted === false) ? $text : $converted;
  }
  $pattern = array("#&quot;#", "#&amp;#", "#&lt;#", "#&gt;#");
  $replace = array('"', '&', '<', '>');
  $text = preg_replace($pattern, $replace, $text);
  $pattern = "#[\s\*\$\(\)!\.,\-\?\/\\\[\]\{\};\:'´`\"&\^+=<>\|]#";
  return strtolower(preg_replace($pattern, '_', $text));
}

function phpbb_profile_field_column($field)
{
  if (!is_array($field) || !isset($field['field_name']) || !is_scalar($field['field_name']))
  {
    return '';
  }

  $column = text_to_column((string) $field['field_name']);
  return preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $column) ? $column : '';
}

function phpbb_profile_field_substr($value, $length)
{
  $length = max(0, (int) $length);
  return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function phpbb_profile_field_input($field, $source)
{
  $column = phpbb_profile_field_column($field);
  if ($column === '' || !is_array($source) || !isset($source[$column]))
  {
    return '';
  }

  $type = isset($field['field_type']) ? (int) $field['field_type'] : -1;
  if ($type === CHECKBOX)
  {
    if (!is_array($source[$column]))
    {
      return '';
    }

    $allowed = isset($field['checkbox_values']) ? explode(',', (string) $field['checkbox_values']) : array();
    $values = array();
    foreach (array_slice($source[$column], 0, 100) as $item)
    {
      if (!is_scalar($item))
      {
        continue;
      }
      $item = htmlspecialchars((string) $item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      if (in_array($item, $allowed, true) && !in_array($item, $values, true))
      {
        $values[] = $item;
      }
    }
    return phpbb_profile_field_substr(implode(',', $values), 60000);
  }

  if (!is_scalar($source[$column]))
  {
    return '';
  }

  $value = htmlspecialchars((string) $source[$column], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  if ($type === RADIO)
  {
    $allowed = isset($field['radio_button_values']) ? explode(',', (string) $field['radio_button_values']) : array();
    return in_array($value, $allowed, true) ? $value : '';
  }

  $maximum = ($type === TEXTAREA)
    ? (isset($field['text_area_maxlen']) ? min(60000, max(0, (int) $field['text_area_maxlen'])) : TEXTAREA_MAXLENGTH)
    : (isset($field['text_field_maxlen']) ? min(TEXT_FIELD_MAXLENGTH, max(0, (int) $field['text_field_maxlen'])) : TEXT_FIELD_MAXLENGTH);
  return phpbb_profile_field_substr($value, $maximum);
}

function phpbb_profile_field_assignments($profile_data, $source, &$profile_names)
{
  global $db;

  $profile_names = array();
  $assignments = array();
  foreach ((array) $profile_data as $field)
  {
    $column = phpbb_profile_field_column($field);
    if ($column === '')
    {
      continue;
    }
    $profile_names[$column] = phpbb_profile_field_input($field, $source);
    $assignments[] = $column . " = '" . $db->sql_escape($profile_names[$column]) . "'";
  }
  return $assignments;
}

function phpbb_profile_display_text($value)
{
  $value = is_scalar($value) ? (string) $value : '';
  return htmlspecialchars(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function displayable_field_data($data, $type)
{
	global $lang;
  $data = phpbb_profile_display_text($data);
  switch($type)
  {
    case TEXTAREA:
      return nl2br(str_replace("\r\n", "\n", $data));
    case TEXT_FIELD:
    case RADIO:
      return $data;
      break;
    case CHECKBOX:
      $data_list = explode(',',$data);
      $tmp = array();
      foreach($data_list as $val)
        if(!empty($val))
          $tmp[] = $val;
      $data_list = $tmp;
      $list_size = count($data_list);
      $data = str_replace(',',', ',$data);

      if($list_size == 0)
        return '';
      elseif($list_size == 1)
        return $data_list[0];
      else
        return substr($data,0,strrpos($data,', ')) . (isset($lang['and']) ? $lang['and'] : ' and ') . substr($data,strrpos($data,', ') + 2);
  }

  return '';
}

function get_topic_udata($postrow_data, $profile_data)
{
	static $cp_udata_cache = array();
	global $userdata;

	$id = (is_array($postrow_data) && isset($postrow_data['user_id'])) ? intval($postrow_data['user_id']) : 0;

	if (!isset($cp_udata_cache[$id]))
	{
		$profile_names = array();
		$cp_udata_cache[$id]['aboves'] = array();
		$cp_udata_cache[$id]['belows'] = array();
		$cp_udata_cache[$id]['author'] = array();
		foreach((array) $profile_data as $field)
		{
			if (empty($userdata['session_logged_in']))
			{
				continue;
			}

			if (!is_array($field) || !isset($field['field_name'], $field['field_type'], $field['topic_location']))
			{
				continue;
			}
			$name = phpbb_profile_display_text($field['field_name']);
			$col_name = phpbb_profile_field_column($field);
			if ($col_name === '')
			{
				continue;
			}
			$type = $field['field_type'];
			$location = $field['topic_location'];

			$field_value = (is_array($postrow_data) && isset($postrow_data[$col_name])) ? $postrow_data[$col_name] : '';
			$profile_names[$name] = displayable_field_data($field_value, $field['field_type']);

			if($location == AUTHOR)
			  $cp_udata_cache[$id]['author'][] = ($profile_names[$name]) ? $name . ': ' . $profile_names[$name] : '';
			elseif($location == ABOVE_SIGNATURE)
			  $cp_udata_cache[$id]['aboves'][] = ($profile_names[$name]) ? $name . ': ' . $profile_names[$name] : '';
			else
			  $cp_udata_cache[$id]['belows'][] = ($profile_names[$name]) ? $name . ': ' . $profile_names[$name] : '';
		}
	}

	return $cp_udata_cache[$id];
}

function get_udata_txt($profile_data, $add = '')
{
	$cp_sql_txt = '';
	foreach((array) $profile_data as $field)
	{
		if (is_array($field) && isset($field['field_name']))
		{
			$column = phpbb_profile_field_column($field);
			if ($column !== '')
			{
				$cp_sql_txt .= ', ' . $add . $column;
			}
		}
	}

	return $cp_sql_txt;
}
?>
