<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_profile_definition_storage.php';

function phpbb_profile_definition_form_map()
{
    return array('field_name'=>'field_name','field_description'=>'field_descrition','field_type'=>'field_type',
        'text_field_default'=>'text_field_default','text_field_maxlen'=>'text_field_maxlen',
        'text_area_default'=>'text_area_default','text_area_maxlen'=>'text_area_maxlen',
        'radio_button_default'=>'radio_default_value','radio_button_values'=>'radio_values',
        'checkbox_default'=>'check_default_values','checkbox_values'=>'checkbox_values',
        'is_required'=>'required','users_can_view'=>'user_can_view','view_in_profile'=>'view_in_profile',
        'profile_location'=>'profile_location','view_in_memberlist'=>'view_in_memberlist',
        'view_in_topic'=>'view_in_topic','topic_location'=>'signature_wrap');
}

function phpbb_profile_definition_form_defaults()
{
    return array('field_name'=>'','field_description'=>'','field_type'=>'0','text_field_default'=>'',
        'text_field_maxlen'=>(string)TEXT_FIELD_MAXLENGTH,'text_area_default'=>'',
        'text_area_maxlen'=>(string)TEXTAREA_MINLENGTH,'radio_button_default'=>'','radio_button_values'=>'',
        'checkbox_default'=>'','checkbox_values'=>'','is_required'=>'0','users_can_view'=>'1',
        'view_in_profile'=>'1','profile_location'=>'2','view_in_memberlist'=>'0','view_in_topic'=>'0','topic_location'=>'1');
}

// The only input layer removed here is common.php's request escaping. Metadata
// read from the database never passes through this request parser.
function phpbb_profile_definition_form_values($request)
{
    if (!is_array($request)) { phpbb_acl_error('Profile_definition_invalid'); }
    $values = array();
    $numeric = array('field_type','text_field_maxlen','text_area_maxlen','is_required','users_can_view',
        'view_in_profile','profile_location','view_in_memberlist','view_in_topic','topic_location');
    $defaults = phpbb_profile_definition_form_defaults();
    foreach (phpbb_profile_definition_form_map() as $key=>$input)
    {
        if (!array_key_exists($input, $request) || !is_string($request[$input])) { phpbb_acl_error('Profile_definition_invalid'); }
        $value = phpbb_request_raw_value($request[$input]);
        if (preg_match('//u', $value) !== 1 || strpos($value, "\0") !== false) { phpbb_acl_error('Profile_definition_invalid'); }
        if (in_array($key, $numeric, true))
        {
            if ($value === '' && in_array($key, array('text_field_maxlen','text_area_maxlen'), true)) { $value = $defaults[$key]; }
            $values[$key] = $value;
            continue;
        }
        if ($key === 'field_name') { $value = trim($value); }
        if (in_array($key, array('radio_button_values','checkbox_values','checkbox_default'), true))
        {
            // Commas are the historic storage separator. Reject rather than
            // silently splitting one option into different choices on reopen.
            if (strpos($value, ',') !== false) { phpbb_acl_error('Profile_definition_options'); }
            $value = str_replace(array("\r\n", "\r"), "\n", $value);
            $value = trim($value, "\n");
            $value = str_replace("\n", ',', $value);
        }
        $values[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    if ($values['radio_button_default'] === '' && $values['radio_button_values'] !== '')
    {
        $options = explode(',', $values['radio_button_values']); $values['radio_button_default'] = $options[0];
    }
    return phpbb_profile_definition_values($values);
}

function phpbb_profile_definition_form_vars($row, $draft = null)
{
    $values = phpbb_profile_definition_form_defaults();
    foreach (phpbb_profile_definition_form_map() as $key=>$input)
    {
        if ($draft !== null)
        {
            // Invalid values stay editable, but never render request HTML or
            // re-use a normalized/staged value as if it were the original draft.
            $value = is_array($draft) && isset($draft[$input]) && is_string($draft[$input]) ? phpbb_request_raw_value($draft[$input]) : '';
        }
        elseif (is_array($row) && isset($row[$key]) && is_scalar($row[$key]))
        {
            $value = html_entity_decode((string)$row[$key], ENT_QUOTES, 'UTF-8');
            if (in_array($key, array('radio_button_values','checkbox_values','checkbox_default'), true)) { $value = str_replace(',', "\n", $value); }
        }
        else { $value = $values[$key]; }
        $values[$key] = $value;
    }
    $vars = array();
    foreach (array('field_name'=>'FIELD_NAME','field_description'=>'FIELD_DESCRIPTION','text_field_default'=>'TEXT_FIELD_DEFAULT',
        'text_field_maxlen'=>'TEXT_FIELD_MAXLENGTH','text_area_default'=>'TEXTAREA_DEFAULT','text_area_maxlen'=>'TEXTAREA_MAXLENGTH',
        'radio_button_values'=>'RADIO_VALUES','radio_button_default'=>'RADIO_DEFAULT','checkbox_values'=>'CHECKBOX_VALUES','checkbox_default'=>'CHECKBOX_DEFAULT') as $key=>$var)
    { $vars[$var] = htmlspecialchars($values[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    foreach (array('field_type'=>array('TEXT_FIELD_CHECKED','TEXTAREA_CHECKED','RADIO_CHECKED','CHECKBOX_CHECKED'),
        'is_required'=>array('NOT_REQUIRED_CHECKED','REQUIRED_CHECKED'), 'users_can_view'=>array('DISALLOW_VIEW_CHECKED','ALLOW_VIEW_CHECKED'),
        'view_in_profile'=>array('NO_VIEW_IN_PROFILE_CHECKED','VIEW_IN_PROFILE_CHECKED'),
        'profile_location'=>array(1=>'CONTACTS_CHECKED',2=>'ABOUT_CHECKED'),
        'view_in_memberlist'=>array('NO_VIEW_IN_MEMBERLIST','VIEW_IN_MEMBERLIST'), 'view_in_topic'=>array('NO_VIEW_IN_TOPIC','VIEW_IN_TOPIC'),
        'topic_location'=>array(1=>'AUTHOR_CHECKED',2=>'ABOVE_SIG_CHECKED',3=>'BELOW_SIG_CHECKED')) as $key=>$choices)
    { foreach ($choices as $value=>$var) { $vars[$var] = $values[$key] === (string)$value ? ' checked="checked"' : ''; } }
    return $vars;
}
