<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_import_storage.php';

function phpbb_arcade_bulk_limits()
{
    // Match the canonical per-game editor and the actual signed SQL columns.
    return array('game_charge'=>'4294967295','game_bonus'=>'65535','at_game_bonus'=>'32767',
        'game_reward'=>'4294967295','highscore_limit'=>'9223372036854775807','at_highscore_limit'=>'32767');
}
function phpbb_arcade_bulk_input($mode,$request)
{
    $limits=phpbb_arcade_bulk_limits();
    if (!is_string($mode)||!isset($limits[$mode])||!is_array($request)||!isset($request[$mode])) { throw new PhpbbOwnedStorageFailure('admin_set_invalid'); }
    foreach ($request as $key=>$raw)
    {
        if (!in_array($key,array('sid',$mode),true)||!is_string($raw)) { throw new PhpbbOwnedStorageFailure('admin_set_invalid'); }
    }
    $value=trim(phpbb_request_raw_value($request[$mode]));
    if (!preg_match('/^[0-9]{1,32}$/D',$value)) { throw new PhpbbOwnedStorageFailure('admin_set_invalid'); }
    $value=ltrim($value,'0');if ($value==='') { $value='0'; }
    if (strlen($value)>strlen($limits[$mode])||(strlen($value)===strlen($limits[$mode])&&strcmp($value,$limits[$mode])>0)) { throw new PhpbbOwnedStorageFailure('admin_set_invalid'); }
    return array('field'=>$mode,'value'=>$value);
}
class PhpbbArcadeBulkWriter extends PhpbbArcadeImportWriter
{
    protected function storage_tables() { return array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,iNA_GAMES); }
    protected function actor()
    {
        global $phpEx;return phpbb_acp_actor($this,'admin_arcade_set.'.$phpEx);
    }
    function set($input)
    {
        global $userdata;
        $actor=$this->actor();$sid=$this->escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
            'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],
            'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id']) as $sql) { $this->rows($sql.' LOCK IN SHARE MODE'); }
        $this->actor();
        $this->rows('SELECT game_id FROM '.iNA_GAMES.' ORDER BY game_id FOR UPDATE');
        $this->query('UPDATE '.iNA_GAMES.' SET '.$input['field']."='".$this->escape($input['value'])."'");
        $this->commit_attempted=true;$this->commit();return $input['value'];
    }
}
function phpbb_arcade_bulk_save($database,$mode,$request)
{
    $input=phpbb_arcade_bulk_input($mode,$request);phpbb_attach_quota_post($request);
    if (!phpbb_request_source_is_same_origin()) { throw new PhpbbOwnedStorageFailure('Session_invalid'); }
    $writer=new PhpbbArcadeBulkWriter($database);
    try { return $writer->set($input); }
    finally { $writer->release(); }
}
