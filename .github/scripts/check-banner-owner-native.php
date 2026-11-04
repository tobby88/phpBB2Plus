<?php
// Exercise the complete production save branch against an owned MariaDB schema.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_BANNER_OWNER_NATIVE') !== '1') {
    echo "Banner owner checks require an explicitly enabled disposable database.\n"; return;
}
date_default_timezone_set('UTC');
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');
require __DIR__ . '/check-attachment-settings-storage.php';
require __DIR__ . '/profile-request-fixture.php';
define('ANONYMOUS', -1); define('POST_FORUM_URL', 'f'); define('BANNERS_TABLE', 'fixture_banner');
ats_load_function($ats_source . 'includes/functions.php', 'get_userdata');
ats_load_function($ats_source . 'admin/admin_banner.php', 'admin_banner_post_scalar');
ats_load_function($ats_source . 'admin/admin_banner.php', 'admin_banner_post_text');
ats_load_function($ats_source . 'admin/admin_banner.php', 'admin_banner_id_storage_ready');
require $ats_source . 'language/lang_english/lang_banner.php';
require $ats_source . 'db/mysqli.php';
$source = file_get_contents($ats_source . 'admin/admin_banner.php');
$bo_banner_source = $source;
$begin = strpos($source, 'phpbb_admin_require_post_session();', strpos($source, 'else if( $mode == "save" )'));
$end = strpos($source, "\n\t}\n\telse if( \$mode == \"delete\" )", $begin);
ats_check($begin !== false && $end > $begin, 'Complete actual banner save branch');
$banner_save_body = substr($source, $begin, $end - $begin);
$begin = strpos($source, "if (!isset(\$_POST['id'])", strpos($source, 'else if( $mode == "delete" )'));
$end = strpos($source, "\$message = \$lang['Banner_removed']", $begin);
ats_check($begin !== false && $end > $begin, 'Actual confirmed banner delete branch');
$banner_delete_body = substr($source, $begin, $end - $begin);
$schema = file_get_contents($ats_source . 'install/schemas/mysql_schema.sql');
ats_check(preg_match('/CREATE TABLE phpbb_banner \([\s\S]+?;/', $schema, $match) === 1, 'Actual banner installer schema');
$banner_schema = str_replace('phpbb_banner', BANNERS_TABLE, $match[0]);
class BannerOwnerDatabase extends sql_db {
    var $fail_users = false;
    var $fail_metadata = ''; var $before_insert = null;
    var $write_queries = 0;
    function sql_query($sql = '', $transaction = false) {
        if (preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER)\b/', $sql)) { $this->write_queries++; }
        if ($this->fail_users && preg_match('/^SELECT .* FROM fixture_users\b/', $sql)) { return false; }
        if ($this->fail_metadata !== '' && strpos($sql,$this->fail_metadata) === 0) { return false; }
        if ($this->before_insert !== null && strpos($sql,'INSERT INTO fixture_banner ') === 0) {
            $hook = $this->before_insert; $this->before_insert = null; call_user_func($hook);
        }
        return parent::sql_query($sql, $transaction);
    }
}
function bo_sql($sql) {
    $r = $GLOBALS['db']->sql_query($sql); ats_check($r !== false, 'Owned banner fixture SQL'); return $r;
}
function bo_rows($sql) {
    $r = bo_sql($sql); try { return $GLOBALS['db']->sql_fetchrowset($r); } finally { $GLOBALS['db']->sql_freeresult($r); }
}
function bo_snapshot() { return serialize(bo_rows('SELECT * FROM fixture_banner ORDER BY banner_id')); }
function bo_save($raw, $id = 0, $sid = 'banner-fixture', $fields = array()) {
    global $db, $lang, $userdata, $phpEx, $banner_save_body;
    $userdata = array('session_id'=>'banner-fixture'); $_SERVER['REQUEST_METHOD'] = 'POST';
    profile_fixture_request(array_merge(array('sid'=>$sid, 'username'=>$raw, 'id'=>(string)$id, 'banner_name'=>'Synthetic banner', 'banner_type'=>'2'), $fields));
    try { eval($banner_save_body); } catch (AttachSettingsExit $e) { return $e->getMessage(); }
    throw new RuntimeException('Actual save branch must finish explicitly');
}
function bo_delete($id, $sid = 'banner-fixture') {
    global $db,$lang,$userdata,$banner_delete_body;
    $userdata = array('session_id'=>'banner-fixture'); $_SERVER['REQUEST_METHOD'] = 'POST';
    profile_fixture_request(array('id'=>(string)$id,'sid'=>$sid));
    try { eval($banner_delete_body); } catch(AttachSettingsExit $e) { return $e->getMessage(); }
    return 'deleted';
}
function bo_text_form($row, $locale, $schedule = false) {
    global $ats_source,$bo_banner_source,$board_config,$theme,$phpbb_root_path,$phpEx,$lang;
    require_once $ats_source . 'includes/template.php';
    $phpbb_root_path=$ats_source;$board_config=array('default_lang'=>$locale,'board_timezone'=>0,'default_dateformat'=>'Y-m-d',
        'board_email_form'=>0,'xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'version'=>'.0.23');
    $theme=array('template_name'=>'fisubsilversh','td_class1'=>'row1','td_class2'=>'row2');$banner_info=$row;
    foreach(array('safe_banner_name','safe_banner_description')as$variable){
        ats_check(preg_match('/^\s*\$'.preg_quote($variable,'/').' = ([^\r\n]+);/m',$bo_banner_source,$m)===1,'Actual text display assignment');
        $$variable=eval('return '.$m[1].';');
    }
    $vars=array();foreach(array('BANNER_NAME','BANNER_DESCRIPTION','BANNER_URL','BANNER_COMMENT')as$key){
        ats_check(preg_match('/\''.preg_quote($key,'/').'\'\s*=>\s*([^\r\n]+),/',$bo_banner_source,$m)===1,'Actual text form binding');$vars[$key]=eval('return '.$m[1].';');
    }
    if ($schedule) {
        $start = strpos($bo_banner_source, '$s_time_week_begin=');
        $end = strpos($bo_banner_source, 'foreach ((array) $lang[\'Banner_spot\']', $start);
        ats_check($start !== false && $end > $start, 'Complete actual schedule selector builder');
        eval(substr($bo_banner_source, $start, $end - $start));
        foreach (array('S_YEAR_BEGIN','S_YEAR_END','S_MONTHS_BEGIN','S_MONTHS_END','S_DATE_BEGIN','S_DATE_END',
            'S_WEEK_BEGIN','S_WEEK_END','S_HOURS_BEGIN','S_HOURS_END','S_MIN_BEGIN','S_MIN_END') as $key) {
            ats_check(preg_match('/\''.preg_quote($key,'/').'\'\s*=>\s*([^\r\n]+),/',$bo_banner_source,$m)===1,'Actual schedule template binding');
            $vars[$key]=eval('return '.$m[1].';');
        }
    }
    $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];
    $template->load_config($ats_source.'templates/fisubsilversh',false);$template->set_filenames(array('body'=>'admin/banner_edit_body.tpl'));
    // The real controller also assigns unrelated selectors and labels. Keep those
    // blank here so the template cannot fall back to a language option array.
    preg_match_all('/\{([A-Z][A-Z0-9_]*)\}/',file_get_contents($ats_source.'templates/fisubsilversh/admin/banner_edit_body.tpl'),$placeholders);
    $template->assign_vars(array_merge(array_fill_keys($placeholders[1],''),$vars));
    ob_start();try{$template->pparse('body');}finally{$html=ob_get_clean();}
    ats_check(preg_match('//u',$html)===1,'Actual banner form stays UTF8');$post=array();
    ats_check(preg_match('/<textarea\b[^>]*name="banner_name"[^>]*>([\s\S]*?)<\/textarea>/i',$html,$m)===1,'Actual banner textarea');$post['banner_name']=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
    foreach(array('banner_description'=>30,'banner_url'=>90,'banner_comment'=>50)as$field=>$limit){
        ats_check(preg_match('/<input\b[^>]*name="'.$field.'"[^>]*>/i',$html,$m)===1&&preg_match('/value="([^"]*)"/',$m[0],$v)===1,'Actual banner text input');
        ats_check(preg_match('/maxlength="([0-9]+)"/',$m[0],$length)===1&&(int)$length[1]>=2*$limit,'Browser UTF16 limit permits every supported supplementary character');
        $post[$field]=html_entity_decode($v[1],ENT_QUOTES,'UTF-8');
    }
    foreach($post as$field=>$value){ats_check($value===$row[$field],'Actual form returns the exact raw stored text');}
    if ($schedule) {
        $post['time_type'] = $row['banner_timetype'];
        foreach (array('date_begin_year','date_end_year','date_begin_month','date_end_month','date_begin_day','date_end_day',
            'date_begin_week','date_end_week','time_begin_hour','time_end_hour','time_begin_min','time_end_min') as $field) {
            ats_check(preg_match('/<select\b[^>]*name="'.$field.'"[^>]*>([\s\S]*?)<\/select>/i',$html,$m)===1,'Actual schedule select');
            ats_check(preg_match_all('/<option\b[^>]*value="([0-9]+)"([^>]*)>/i',$m[1],$options,PREG_SET_ORDER)>0,'Actual browser schedule options');
            $post[$field]=$options[0][1];
            foreach($options as$option){if(preg_match('/\bselected\b/i',$option[2])){$post[$field]=$option[1];break;}}
        }
    }
    return$post;
}
function bo_selected_at($hour_now, $date_now, $week_now) {
    global $ats_source,$db;
    $header = file_get_contents($ats_source.'includes/page_header.php');
    $start = strpos($header, '$sql = "SELECT DISTINCT banner_id, banner_name, banner_spot');
    $end = strpos($header, ';', $start);
    ats_check($start !== false && $end > $start, 'Actual frontend banner selection query');
    $sql_level=0; eval(substr($header,$start,$end-$start+1));
    return count(bo_rows($sql)) === 1;
}
$port = getenv('PHPBB_BANNER_OWNER_PORT') ?: '3306';
ats_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Loopback port');
$host = '127.0.0.1:' . $port; $password = getenv('PHPBB_BANNER_OWNER_PASSWORD') ?: '';
$fixture = 'codex_banner_owner_' . bin2hex(phpbb_random_bytes(8));
$control = new sql_db($host, 'root', $password, '', false);
ats_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Owned schema');
$db = null; $cases = 0;
try {
    $db = new BannerOwnerDatabase($host, 'root', $password, $fixture, false);
    set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    bo_sql('CREATE TABLE fixture_users(user_id INT PRIMARY KEY, username VARCHAR(25) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    bo_sql($banner_schema);
    foreach (array('', 'NO_BACKSLASH_ESCAPES', 'STRICT_TRANS_TABLES', 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES') as $mode) {
        bo_sql("SET SESSION sql_mode='" . $mode . "'");
        foreach (array('123', '0', '000123', '1e2', '+123', '-1', '.5', '1.2', 'Plain', 'Müller', 'A&B', "O'Reilly", 'C:\\notes', "A\\'B", 'Trailing\\', '&#39;', '😀', ' 123 ') as $raw) {
            foreach (array(ENT_QUOTES, ENT_COMPAT) as $flags) {
                bo_sql('DELETE FROM fixture_users'); bo_sql('TRUNCATE TABLE fixture_banner');
                $key = phpbb_username_key($raw, $flags); ats_check($key !== '', 'Valid canonical fixture identity');
                bo_sql("INSERT INTO fixture_users VALUES(7,'" . $db->sql_escape($key) . "'),(123,'Other numeric ID'),(100,'Other exponent ID'),(1,'Other decimal ID'),(-1,'Anonymous')");
                $users = serialize(bo_rows('SELECT * FROM fixture_users ORDER BY user_id'));
                foreach (array(0, 1) as $id) {
                    $message = bo_save($raw, $id);
                    $rows = bo_rows('SELECT banner_id,banner_owner FROM fixture_banner');
                    ats_check(strpos($message, $lang[$id ? 'Banner_updated' : 'Banner_added']) === 0, 'Actual save acknowledges success: ' . $raw);
                    ats_check(count($rows) === 1 && (int)$rows[0]['banner_id'] === 1 && (int)$rows[0]['banner_owner'] === 7,
                        'Actual banner INSERT/UPDATE must select the named account, never a numeric user ID: ' . $raw);
                    ats_check(serialize(bo_rows('SELECT * FROM fixture_users ORDER BY user_id')) === $users, 'Owner lookup never changes accounts');
                    $cases++;
                }
                // Loading an existing banner still resolves its stored numeric owner ID.
                ats_check((int)get_userdata(7)['user_id'] === 7 && get_userdata(7)['username'] === $key, 'Existing numeric ID reader unchanged');
                $before = bo_snapshot();
                foreach (array('Missing', str_repeat('x', 26), '', array('123'), '123 AND 1=1') as $invalid) {
                    ats_check(bo_save($invalid, 1) === $lang['Missing_banner_owner'] && bo_snapshot() === $before, 'Missing/malformed owner cannot rewrite banner'); $cases++;
                }
                ats_check(bo_save($raw, 1, 'wrong-session') === 'Invalid administration request.' && bo_snapshot() === $before, 'Actual session guard remains before owner lookup/write'); $cases++;
                $db->fail_users = true;
                try { $message = bo_save($raw, 1); } finally { $db->fail_users = false; }
                ats_check($message === 'Failed obtaining user data' && bo_snapshot() === $before, 'Failed owner read cannot rewrite banner'); $cases++;
            }
        }
        bo_sql('DELETE FROM fixture_users');
        foreach (array(7=>ENT_QUOTES, 8=>ENT_COMPAT) as $id=>$flags) {
            bo_sql("INSERT INTO fixture_users VALUES(" . $id . ",'" . $db->sql_escape(phpbb_username_key("O'Reilly", $flags)) . "')");
        }
        $before = bo_snapshot();
        ats_check(bo_save("O'Reilly", 1) === $lang['Missing_banner_owner'] && bo_snapshot() === $before, 'Ambiguous historical owner cannot select either account'); $cases++;
        bo_sql('DELETE FROM fixture_users'); bo_sql("INSERT INTO fixture_users VALUES(7,'Owner')");
        foreach (array("O'Reilly", 'A"B', 'C:\\notes', "A\\'B", 'Trailing\\', '&amp;', '&#39;', '<literal>', 'Grüße 😀', '0', '  Trimmed  ') as $text) {
            bo_sql('TRUNCATE TABLE fixture_banner');
            $fields = array('banner_name'=>$text, 'banner_description'=>$text, 'banner_comment'=>$text,
                'banner_url'=>"https://example.invalid/?a='" . rawurlencode($text) . "'&b=2");
            foreach (array(0, 1, 1) as $id) {
                bo_save('Owner', $id, 'banner-fixture', array_merge($fields, array('f'=>'9')));
                $row = bo_rows('SELECT banner_name,banner_description,banner_comment,banner_url,banner_owner,banner_forum FROM fixture_banner');
                ats_check(count($row) === 1 && (int)$row[0]['banner_owner'] === 7, 'Metadata save retains numeric owner');
                ats_check((int)$row[0]['banner_forum'] === 9, 'Actual INSERT and repeated UPDATE preserve the selected forum');
                foreach ($fields as $field=>$value) {
                    ats_check($row[0][$field] === trim($value), 'Actual banner text round-trip is byte exact: ' . $field);
                    $fields[$field] = $row[0][$field];
                }
                $cases++;
            }
        }
    }
    $text_cases=0;$forms=0;$prefix='https://example.invalid/?q=';
    $valid_text=array(
        'banner_name'=>array(str_repeat('ä',128),str_repeat('😀',64).'Z',"Grüße &amp; &#39; <b>literal</b> \\ \" '",str_repeat('x',65535),str_repeat('ä',32767).'x',str_repeat('😀',16383).'abc'),
        'banner_description'=>array(str_repeat('ä',30),str_repeat('😀',30),str_repeat("e\xcc\x81",15)),
        'banner_url'=>array($prefix.str_repeat('ä',90-strlen($prefix)),$prefix.str_repeat('😀',90-strlen($prefix))),
        'banner_comment'=>array(str_repeat('ä',50),str_repeat('😀',50),'0')
    );
    bo_sql('DELETE FROM fixture_users');bo_sql("INSERT INTO fixture_users VALUES(7,'Owner')");
    foreach(array('english','german')as$locale){
        require $ats_source.'language/lang_'.$locale.'/lang_main.php';require $ats_source.'language/lang_'.$locale.'/lang_banner.php';
        foreach(array('','NO_BACKSLASH_ESCAPES','STRICT_TRANS_TABLES','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES')as$mode){
            bo_sql("SET SESSION sql_mode='$mode'");
            foreach($valid_text as$field=>$values){foreach($values as$value){
                bo_sql('TRUNCATE TABLE fixture_banner');$fields=array($field=>$value);
                foreach(array(0,1,1)as$id){
                    $message=bo_save('Owner',$id,'banner-fixture',$fields);ats_check(strpos($message,$lang[$id?'Banner_updated':'Banner_added'])===0,'Complete UTF8 save succeeds in every SQL mode');
                    $rows=bo_rows('SELECT banner_name,banner_description,banner_comment,banner_url FROM fixture_banner');ats_check(count($rows)===1&&$rows[0][$field]===$value,'Every supported UTF8 value preserved without clipping');
                    $fields=bo_text_form($rows[0],$locale);$forms++;$text_cases++;
                }
            }}
            bo_sql('TRUNCATE TABLE fixture_banner');bo_save('Owner');$before=bo_snapshot();
            foreach(array('banner_name'=>array(0,65535),'banner_description'=>array(30,120),'banner_url'=>array(90,360),'banner_comment'=>array(50,200))as$field=>$limits){
                $invalid=array(array("bad\xc3",'Banner_text_invalid'),array("bad\xf0\x28\x8c\x28",'Banner_text_invalid'),array("bad\0text",'Banner_text_invalid'),array(array('unexpected'),'Banner_text_invalid'));
                if($limits[0]){$invalid[]=array(str_repeat('a',$limits[0]+1),'Banner_text_too_long');$invalid[]=array(str_repeat('😀',$limits[0]+1),'Banner_text_too_long');}
                else{$invalid[]=array(str_repeat('a',65536),'Banner_text_too_large');$invalid[]=array(str_repeat('😀',16384),'Banner_text_too_large');}
                foreach($invalid as$bad){foreach(array(0,1)as$id){
                    $writes=$db->write_queries;$expected=sprintf($lang[$bad[1]],$lang[ucfirst($field)],$limits[0]);
                    ats_check(bo_save('Owner',$id,'banner-fixture',array($field=>$bad[0]))===$expected&&$db->write_queries===$writes&&bo_snapshot()===$before,'Invalid or oversized input rejected with translated field message before any SQL write');$text_cases++;
                }}
            }
        }
    }
    echo 'Native banner text: '.$text_cases.' cases, '.$forms." actual forms PASS.\n";
    $schedule_cases=0;$schedule_forms=0;
    $base=array('banner_active'=>'1','banner_level'=>'0','time_begin_hour'=>'08','time_begin_min'=>'00',
        'time_end_hour'=>'09','time_end_min'=>'09','date_begin_week'=>'1','date_end_week'=>'5',
        'date_begin_year'=>'2026','date_begin_month'=>'01','date_begin_day'=>'05',
        'date_end_year'=>'2026','date_end_month'=>'02','date_end_day'=>'09');
    $rules=array(
        array(array('time_type'=>'0'),array(0,0,0,0),array(array(0,19000101,0,true))),
        array(array('time_type'=>'2'),array(800,909,0,0),array(array(759,20260105,1,false),array(800,20260105,1,true),array(909,20260105,1,true),array(910,20260105,1,false))),
        array(array('time_type'=>'4'),array(800,909,1,5),array(array(800,20260105,1,true),array(909,20260109,5,true),array(800,20260104,0,false),array(800,20260110,6,false),array(759,20260105,1,false))),
        array(array('time_type'=>'6'),array(800,909,20260105,20260209),array(array(800,20260104,0,false),array(800,20260105,1,true),array(909,20260209,1,true),array(800,20260210,2,false),array(759,20260105,1,false),array(910,20260209,1,false))),
        array(array('time_type'=>'6','date_begin_year'=>'2024','date_end_year'=>'2024','date_begin_month'=>'02','date_end_month'=>'02','date_begin_day'=>'29','date_end_day'=>'29',
            'time_begin_hour'=>'00','time_begin_min'=>'00','time_end_hour'=>'23','time_end_min'=>'59'),array(0,2359,20240229,20240229),array(array(0,20240229,4,true),array(2359,20240229,4,true),array(800,20240301,5,false)))
    );
    foreach(array('english','german')as$locale){
        require $ats_source.'language/lang_'.$locale.'/lang_main.php';require $ats_source.'language/lang_'.$locale.'/lang_banner.php';
        foreach(array('','NO_BACKSLASH_ESCAPES','STRICT_TRANS_TABLES','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES')as$mode){
            bo_sql("SET SESSION sql_mode='$mode'");
            foreach($rules as$rule){
                bo_sql('TRUNCATE TABLE fixture_banner');$fields=array_merge($base,$rule[0]);
                foreach(array(0,1,1)as$id){
                    $message=bo_save('Owner',$id,'banner-fixture',$fields);
                    ats_check(strpos($message,$lang[$id?'Banner_updated':'Banner_added'])===0,'Actual schedule save succeeds');
                    $row=bo_rows('SELECT * FROM fixture_banner')[0];$actual=array();
                    foreach(array('time_begin','time_end','date_begin','date_end')as$key){$actual[]=(int)$row[$key];}
                    ats_check($actual===$rule[1],'HHMM and YYYYMMDD keep every zero-padded component');
                    foreach($rule[2]as$point){ats_check(bo_selected_at($point[0],$point[1],$point[2])===$point[3],'Actual frontend query includes exactly the intended schedule boundaries');}
                    $form=bo_text_form($row,$locale,true);$schedule_forms++;
                    foreach(array('time_begin_hour','time_begin_min','time_end_hour','time_end_min')as$key){
                        if($fields['time_type']!=='0'){ats_check((int)$form[$key]===(int)$fields[$key],'Actual selected time survives reopening');}
                    }
                    if($fields['time_type']==='6'){foreach(array('date_begin_year','date_end_year','date_begin_month','date_end_month','date_begin_day','date_end_day')as$key){ats_check((int)$form[$key]===(int)$fields[$key],'Actual selected date survives reopening');}}
                    $fields=array_merge($fields,$form);$schedule_cases++;
                }
            }
            $before=bo_snapshot();
            foreach(array('begin','end')as$side){foreach(array(array('2026','02','29'),array('2026','02','31'),array('2026','04','31'),array('1900','02','29'))as$bad){
                foreach(array(0,1)as$id){$writes=$db->write_queries;
                    $fields=array_merge($base,array('time_type'=>'6','date_'.$side.'_year'=>$bad[0],'date_'.$side.'_month'=>$bad[1],'date_'.$side.'_day'=>$bad[2]));
                    ats_check(bo_save('Owner',$id,'banner-fixture',$fields)===$lang['Banner_invalid_date']&&$db->write_queries===$writes&&bo_snapshot()===$before,'Nonexistent date rejected before insert or update with translated message');$schedule_cases++;
                }
            }}
            $fields=array_merge($base,array('time_type'=>'6','date_begin_month'=>'0'));
            ats_check(bo_save('Owner',1,'banner-fixture',$fields)===$lang['Missing_date']&&bo_snapshot()===$before,'Incomplete date retains existing message');$schedule_cases++;
        }
    }
    echo 'Native banner schedules: '.$schedule_cases.' cases, '.$schedule_forms." actual forms PASS.\n";
    $other = new sql_db($host,'root',$password,$fixture,false);
    try {
        foreach (array('','NO_BACKSLASH_ESCAPES','STRICT_TRANS_TABLES','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES') as $mode) {
            bo_sql("SET SESSION sql_mode='$mode'"); ats_check($other->sql_query("SET SESSION sql_mode='$mode'"),'Peer SQL mode');
            bo_sql('TRUNCATE TABLE fixture_banner'); $interleaved = false; $peer_message = '';
            $db->before_insert = function() use($other,&$interleaved,&$peer_message) {
                $original = $GLOBALS['db']; $GLOBALS['db'] = $other; $interleaved = true;
                try { $peer_message = bo_save('Owner'); } finally { $GLOBALS['db'] = $original; }
            };
            $message = bo_save('Owner'); $rows = bo_rows('SELECT banner_id,banner_name FROM fixture_banner ORDER BY banner_id');
            ats_check($interleaved && strpos($message,$lang['Banner_added']) === 0 && strpos($peer_message,$lang['Banner_added']) === 0,
                'Both complete saves really interleave on two independent connections');
            ats_check(count($rows) === 2 && $rows[0]['banner_id'] === '1' && $rows[1]['banner_id'] === '2','Concurrent creates receive distinct database IDs'); $cases++;
            bo_save('Owner',1,'banner-fixture',array('banner_name'=>'Edited first'));
            $rows = bo_rows('SELECT banner_id,banner_name FROM fixture_banner ORDER BY banner_id');
            ats_check($rows[0]['banner_name'] === 'Edited first' && $rows[1]['banner_name'] === 'Synthetic banner','Editing one allocated ID cannot rewrite the other banner'); $cases++;
            ats_check(bo_delete(1) === 'deleted' && bo_rows('SELECT banner_id FROM fixture_banner')[0]['banner_id'] === '2','Deleting one allocated ID leaves the other intact'); $cases++;
        }
    } finally { $other->sql_close(); }
    foreach (array('SHOW FULL COLUMNS','SHOW INDEX','SELECT ENGINE FROM') as $failure) {
        $before = bo_snapshot(); $db->fail_metadata = $failure;
        try {
            ats_check(bo_save('Owner') === $lang['Banner_storage_upgrade'] && bo_save('Owner',2) === $lang['Banner_storage_upgrade']
                && bo_delete(2) === $lang['Banner_storage_upgrade'],'Every metadata failure prevents all banner mutations');
        } finally { $db->fail_metadata = ''; }
        ats_check(bo_snapshot() === $before,'Failed storage checks preserve the original banner'); $cases++;
    }
    bo_sql('ALTER TABLE fixture_banner MODIFY banner_id MEDIUMINT UNSIGNED NOT NULL'); $before = bo_snapshot();
    ats_check(bo_save('Owner') === $lang['Banner_storage_upgrade'] && bo_save('Owner',2) === $lang['Banner_storage_upgrade']
        && bo_delete(2) === $lang['Banner_storage_upgrade'] && bo_snapshot() === $before,'Legacy storage never silently inserts ID zero or rewrites ambiguous IDs'); $cases++;
    echo 'Native banner owners: ' . $cases . " complete save/identity/session/failure cases PASS.\n";
} finally {
    if ($db !== null) { $db->sql_close(); }
    $control->sql_query('DROP DATABASE ' . $fixture); $control->sql_close(); restore_error_handler();
}
