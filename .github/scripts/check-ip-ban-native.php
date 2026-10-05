<?php
// Actual ACP parser/owned writer followed by actual manual/automatic sessions.
// Explicitly enabled disposable schema only; no live ban or account is touched.
if (PHP_SAPI!=='cli' || getenv('PHPBB_IP_BAN_NATIVE')!=='1') { echo "Native IP ban checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_ADMIN_BAN_NATIVE=1');putenv('PHPBB_ADMIN_BAN_PORT='.(getenv('PHPBB_IP_BAN_PORT')?:'3306'));putenv('PHPBB_ADMIN_BAN_PASSWORD='.(getenv('PHPBB_IP_BAN_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-admin-ban-native.php');$cut=strpos($source,"\n\$cases=\$serialized=0;\ntry {");
if ($cut===false) { throw new RuntimeException('ACP fixture boundary changed'); }
$head='';foreach(token_get_all(substr($source,0,$cut)) as $token) { $head.=is_array($token)?($token[0]===T_DIR?var_export(__DIR__,true):$token[1]):$token; }eval(substr($head,5));
$cases=0;
class IpBanTemplate { var $vars=array(); function set_filenames($files) {} function assign_vars($vars) {$this->vars=array_merge($this->vars,$vars);} }
$get_start=strpos($controller,"\nelse\n",$start);$get_end=strpos($controller,"\n\$template->pparse('body');",$get_start);
ats_check($get_start!==false&&$get_end>$get_start,'Complete actual ACP GET branch');$ban_get_body='if(true)'.substr($controller,$get_start+strlen("\nelse"),$get_end-$get_start-strlen("\nelse"));
function ip_ban_get() { global $db,$template,$phpEx,$lang,$ban_get_body;$template=new IpBanTemplate();eval($ban_get_body);return $template->vars; }
try {
    set_error_handler(function($s,$m) { if (error_reporting()&$s) { throw new RuntimeException($m); } });
    $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
    foreach(array('users','sessions','sessions_keys','banlist','config','jr_admin_users') as $table) { ats_check(preg_match('/CREATE TABLE `?phpbb_'.$table.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical participant');lp_query(str_replace('phpbb_'.$table,$table==='jr_admin_users'?'fixture_jr':'fixture_'.$table,$m[0])); }
    $patterns=array();
    for ($bits=0;$bits<16;$bits++) { $parts=array('127','0','0','1');for($i=0;$i<4;$i++){if($bits&(1<<$i)){$parts[$i]='*';}}$patterns[]=implode('.',$parts); }
    $patterns=array_merge($patterns,array('127.0.0.255','255.0.0.1','127.255.0.1','127.0.255.1','128.0.0.1','127.0.0.254 - 127.0.0.255'));
    foreach(array('', 'STRICT_ALL_TABLES','ANSI_QUOTES','NO_BACKSLASH_ESCAPES') as $mode) {
        $main->sql_query("SET SESSION sql_mode='$mode'");lp_query("SET SESSION sql_mode='$mode'");
        foreach(array('root','delegated') as $actor) { foreach($patterns as $pattern) {
            ban_reset($actor);lp_query("UPDATE fixture_sessions SET session_ip='cb020304' WHERE session_user_id=1");$before=ban_snapshot();
            $out=ban_run(array('ban_ip'=>$pattern));
            if($pattern==='*.*.*.*') { ats_check(!ban_success($out)&&ban_snapshot()===$before,'All-address rule cannot disable actor');$cases++;continue; }
            ats_check(ban_success($out),'Actual ACP stores complete masked IP rule');$rules=lp_rows('SELECT ban_ip,ban_ip_mask FROM fixture_banlist ORDER BY ban_id');$matches=false;$other=false;
            foreach($rules as $rule) { ats_check($rule['ban_ip_mask']!==''&&phpbb_ip_ban_valid($rule['ban_ip'],$rule['ban_ip_mask']),'New rules always explicit');$matches=$matches||phpbb_ip_ban_matches($rule['ban_ip'],$rule['ban_ip_mask'],'7f000001');$other=$other||phpbb_ip_ban_matches($rule['ban_ip'],$rule['ban_ip_mask'],'7f000002'); }
            ats_check(count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2'))===($matches?0:1),'Existing mixed-case member session uses same rule');
            ats_check(count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=4'))===($other?0:1),'Nonmatching/matching second session handled exactly');
            foreach(array(false,true) as $automatic) {
                $result=lp_login(false,array('automatic'=>$automatic,'setup'=>function()use($rules){foreach($rules as $rule){lp_insert('fixture_banlist',array('ban_ip'=>$rule['ban_ip'],'ban_ip_mask'=>$rule['ban_ip_mask']));}}));
                ats_check($result===($matches?'denied':'published')&&count($lp_cookies)===($matches?0:2),'Same stored rule controls actual manual/automatic publication');$cases++;
            }
        } }
    }
    // SQL and PHP predicates agree for every wildcard position, independent of
    // collation/case and 32-bit PHP arithmetic. Corruption is separately flagged.
    foreach(array('', 'ANSI_QUOTES','NO_BACKSLASH_ESCAPES') as $mode) {
        lp_query("SET SESSION sql_mode='$mode'");
        foreach(array('127.0.0.1','255.255.255.255','0.0.0.0') as $base) { for($bits=0;$bits<16;$bits++) {
            $parts=explode('.',$base);for($i=0;$i<4;$i++){if($bits&(1<<$i)){$parts[$i]='*';}}$rule=phpbb_ip_ban_parse(implode('.',$parts));lp_query('DELETE FROM fixture_banlist');lp_insert('fixture_banlist',array('ban_ip'=>strtoupper($rule['ip']),'ban_ip_mask'=>strtoupper($rule['mask'])));
            foreach(array('7f000001','ff00ffff','00000000','ffffffff','cb020304') as $ip) {
                $row=lp_rows('SELECT '.phpbb_ip_ban_sql($ip).' AS matched,'.phpbb_ip_ban_invalid_sql().' AS invalid FROM fixture_banlist')[0];
                ats_check((bool)$row['matched']===phpbb_ip_ban_matches($rule['ip'],$rule['mask'],$ip)&&(int)$row['invalid']===0,'Native SQL exactly matches bounded PHP predicate');$cases++;
            }
        } }
    }
    foreach(array('7F0000FF','7FFF00FF','FFFFFFFF','7F00FFFF') as $legacy) {
        $result=lp_login(false,array('setup'=>function()use($legacy){lp_insert('fixture_banlist',array('ban_ip'=>$legacy));}));
        ats_check($result===(phpbb_ip_ban_matches($legacy,'','7f000001')?'denied':'published'),'Historical uppercase prefixes retain original login behavior');$cases++;
    }
    ban_reset();lp_insert('fixture_banlist',array('ban_ip'=>'7f000000','ban_ip_mask'=>'ffffff00'));
    ats_check(ban_success(ban_run(array('ban_ip'=>'127.0.0.0')))&&count(lp_rows("SELECT ban_id FROM fixture_banlist WHERE ban_ip='7f000000'"))===2,'Same address with different masks is not deduplicated');
    ats_check(ban_success(ban_run(array('ban_ip'=>'127.0.0.0,127.0.0.0')))&&count(lp_rows("SELECT ban_id FROM fixture_banlist WHERE ban_ip='7f000000'"))===2,'Exact repeated address/mask is idempotent');$cases+=2;
    ban_reset();lp_insert('fixture_banlist',array('ban_ip'=>'7f0000ff','ban_ip_mask'=>'ffffffff','ban_email'=>'mixed@example.invalid'));lp_insert('fixture_banlist',array('ban_ip'=>'7fff00ff'));
    $rendered=ip_ban_get();ats_check(strpos($rendered['S_UNBAN_IPLIST_SELECT'],'127.0.0.255')!==false&&strpos($rendered['S_UNBAN_IPLIST_SELECT'],'127.255.0.*')!==false&&strpos($rendered['S_UNBAN_EMAILLIST_SELECT'],'mixed@example.invalid')!==false,'Complete actual GET distinguishes literal 255/legacy wildcard and exposes both mixed components');$cases++;
    foreach(array(array('7f000001','ffffff01'),array('7f010001','ff00ffff'),array('','ffffffff')) as $bad) {
        ban_reset();lp_insert('fixture_banlist',array('ban_ip'=>$bad[0],'ban_ip_mask'=>$bad[1]));$before=ban_snapshot();
        ats_check(!ban_success(ban_run(array('username'=>'Member')))&&ban_snapshot()===$before,'Corrupt rule refuses whole ACP form');
        $result=lp_login(false,array('setup'=>function()use($bad){lp_insert('fixture_banlist',array('ban_ip'=>$bad[0],'ban_ip_mask'=>$bad[1]));}));
        ats_check($result==='denied'&&$lp_cookies===array(),'Corrupt explicit mask cannot silently permit login');$cases+=2;
    }
    foreach(array('127.0.0.256','not an ip','0.0.0.0 - 0.0.16.0','128.0.0.1 - 127.255.255.255') as $bad) {ban_reset();$before=ban_snapshot();ats_check(!ban_success(ban_run(array('username'=>'Member','ban_ip'=>$bad)))&&ban_snapshot()===$before,'Invalid actual IP form cannot retain earlier user ban');$cases++;}
    ban_reset();lp_query('ALTER TABLE fixture_banlist DROP ban_ip_mask');$before=lp_rows('SELECT * FROM fixture_banlist');
    ats_check(!ban_success(ban_run(array('username'=>'Member')))&&lp_rows('SELECT * FROM fixture_banlist')===$before,'Unmigrated ACP storage refuses all writes');
    ats_check(lp_login(false)==='denied'&&$lp_cookies===array(),'Unmigrated session storage refuses capability publication');$cases+=2;
    echo 'Native masked IP bans: '.$cases." complete controller/login and SQL parity cases passed.\n";
} finally {$main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
