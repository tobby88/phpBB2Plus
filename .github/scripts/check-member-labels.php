<?php
require __DIR__ . '/member-label-fixture.php';
class MemberLabelRows { var $rows; function __construct($rows) { $this->rows=$rows; } }
class MemberLabelDatabase {
    var $key; var $id;
    function sql_query($sql) {
        al_check(strpos($sql,'SELECT ')===0,'Display performs SELECT only');
        if (strpos($sql,'SELECT c.group_color, u.user_id')===0) { return new MemberLabelRows(array()); }
        return new MemberLabelRows(array(array('username'=>$this->key,'user_id'=>$this->id,'user_color_group'=>5,'group_color'=>'#123456')));
    }
    function sql_fetchrow($result) { return array_shift($result->rows); }
    function sql_freeresult($result) { return true; }
}
$db=new MemberLabelDatabase();
ml_suite(function($id,$key) { $GLOBALS['db']->id=$id; $GLOBALS['db']->key=$key; return array('user_id'=>$id,'username'=>$key); });
echo 'Member labels: ',$ml_cases,' cases, ',$al_checks,' assertions, ',$ml_templates," actual templates PASS; stored identities, colored names, literal backslashes, EN/DE captions and former-member privacy.\n";
restore_error_handler();
