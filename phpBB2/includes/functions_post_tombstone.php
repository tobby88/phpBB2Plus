<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}

function phpbb_post_tombstone_choice($database)
{
    $value=isset($_POST['delete_permanently'])?$_POST['delete_permanently']:'0';
    if(!(is_int($value)||is_string($value))||!in_array((string)$value,array('0','1'),true)){throw new PhpbbPostSubmitException('Post_delete_invalid_choice');}
    $rows=$database->rows('SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='post_delete_allow_permanent' FOR UPDATE");
    if(count($rows)>1||(count($rows)===1&&!in_array((string)$rows[0]['config_value'],array('0','1'),true))){throw new PhpbbPostSubmitException('Posting_submit_upgrade');}
    // Missing policy is deliberately conservative during an interrupted update.
    if((string)$value==='1'&&(!$rows||(string)$rows[0]['config_value']!=='1')){throw new PhpbbPostSubmitException('Post_delete_permanent_disabled');}
    return (string)$value!=='1';
}
function phpbb_post_is_deleted($row)
{
    return is_array($row)&&isset($row['post_deleted'])&&(string)$row['post_deleted']==='1';
}
