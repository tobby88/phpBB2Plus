<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_profile_definition_retirement.php';

function phpbb_profile_retirement_token($request, $key)
{
    return is_array($request) && isset($request[$key]) && is_string($request[$key])
        && preg_match('/^[a-f0-9]{64}$/D', $request[$key]) ? $request[$key] : '';
}

// Keep operation/revision tokens unchanged on uncertain failures. Re-reading
// the active definition here would break retries after a lost COMMIT response.
function phpbb_profile_retirement_submit($database, $request, $mode, $id)
{
    global $lang;
    $writer = null;
    try
    {
        phpbb_attach_quota_post($request);
        if (!in_array($mode, array('confirmdelete','confirmrestore'), true)) { phpbb_acl_error('Profile_definition_invalid'); }
        if (isset($request['cancel']) || !isset($request['confirm']) || !is_string($request['confirm'])) { return array('cancel', ''); }
        $operation = phpbb_profile_retirement_token($request, 'definition_operation');
        $writer = new PhpbbProfileDefinitionRetirement($database, $request);
        if ($mode === 'confirmdelete')
        { $writer->retire($id, phpbb_profile_retirement_token($request, 'definition_revision'), $operation); }
        else { $writer->restore($operation); }
        if (!$writer->confirmed) { phpbb_acl_error('Profile_retirement_failed'); }
        return array('success', '');
    }
    catch (Exception $failure) { return array('retry', $lang['Profile_retirement_failed']); }
    catch (Throwable $failure) { return array('retry', $lang['Profile_retirement_failed']); }
    finally { if ($writer !== null) { $writer->release(); } }
}

function phpbb_profile_retirement_hidden($mode, $id, $operation, $revision)
{
    return '<input type="hidden" name="mode" value="' . phpbb_admin_html($mode) . '" />'
        . '<input type="hidden" name="pfid" value="' . phpbb_admin_html((string)$id) . '" />'
        . '<input type="hidden" name="definition_operation" value="' . phpbb_admin_html($operation) . '" />'
        . '<input type="hidden" name="definition_revision" value="' . phpbb_admin_html($revision) . '" />'
        . phpbb_admin_session_field();
}

function phpbb_profile_retirement_label($snapshot)
{
    global $lang;
    $row = is_string($snapshot) ? json_decode($snapshot, true) : null;
    return is_array($row) && isset($row['field_name']) && is_string($row['field_name'])
        ? phpbb_profile_display_text($row['field_name']) : phpbb_admin_html($lang['Profile_retirement_unknown']);
}
