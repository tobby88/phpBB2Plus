<?php
define('IN_PHPBB',true);
require dirname(dirname(__DIR__)).'/phpBB2/includes/functions_ban.php';
function ip_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$cases=0;
foreach (array('127.0.0.1','255.255.255.255','0.0.0.0','128.255.255.0') as $base) {
    $parts=explode('.',$base);
    for ($bits=0;$bits<16;$bits++) {
        $pattern=$parts;
        for ($i=0;$i<4;$i++) { if ($bits&(1<<$i)) { $pattern[$i]='*'; } }
        $text=implode('.',$pattern); $rule=phpbb_ip_ban_parse($text);
        ip_check($rule && phpbb_ip_ban_valid($rule['ip'],$rule['mask']) && phpbb_ip_ban_format($rule['ip'],$rule['mask'])===$text,'Every wildcard combination roundtrips');
        ip_check(phpbb_ip_ban_matches($rule['ip'],$rule['mask'],phpbb_ip_ban_parse($base)['ip']),'Original address matches');
        for ($i=0;$i<4;$i++) {
            $other=$parts; $other[$i]=(string)(((int)$other[$i]+1)%256);
            ip_check(phpbb_ip_ban_matches($rule['ip'],$rule['mask'],phpbb_ip_ban_parse(implode('.',$other))['ip'])===(bool)($bits&(1<<$i)),'Changing literal/wildcard octet has exact meaning'); $cases++;
        }
    }
}
foreach (array('7F0000FF'=>array('127.0.0.*',true),'7FFF00FF'=>array('127.255.0.*',false),'FFFFFFFF'=>array('255.*.*.*',false),'7F00FFFF'=>array('127.0.*.*',true)) as $ip=>$case) {
    ip_check(phpbb_ip_ban_format($ip,'')===$case[0] && phpbb_ip_ban_matches($ip,'','7f000001')===$case[1],'Legacy uppercase/trailing prefix meaning unchanged'); $cases++;
}
foreach (array('',null,true,array('127.0.0.1'),'256.0.0.1','1.2.3','1.2.3.4.5','1.2.3.-1','1.2.3.4\n','1e2.2.3.4') as $bad) { ip_check(phpbb_ip_ban_parse($bad)===false,'Invalid parser input'); $cases++; }
foreach (array(array('7f010001','ff00ffff'),array('7f000001','ffffff01'),array('','ffffffff'),array('7f000001',null),array('7f000001','ffffffff\n')) as $bad) { ip_check(!phpbb_ip_ban_valid($bad[0],$bad[1]),'Corrupt rule refused'); $cases++; }
foreach (array("ban_ip;DELETE",'a b','a.b.c',"'deadbeef' OR 1",array('ban_ip')) as $bad) { $caught=false; try { phpbb_ip_ban_sql('7f000001',false,$bad); } catch (UnexpectedValueException $e) { $caught=true; } ip_check($caught,'SQL operand cannot inject syntax'); $cases++; }
foreach (array(array('127.255.255.254','128.0.0.1',4),array('255.255.255.254','255.255.255.255',2),array('0.0.0.0','0.0.15.255',4096)) as $range) {
    $rules=phpbb_ip_ban_range($range[0],$range[1]); ip_check(count($rules)===$range[2] && $rules[0]===phpbb_ip_ban_parse($range[0]) && end($rules)===phpbb_ip_ban_parse($range[1]),'Ranges cross byte and signed boundaries without integer-width assumptions'); $cases++;
}
foreach (array(array('0.0.0.0','0.0.16.0'),array('128.0.0.1','127.255.255.255'),array('1.2.3.*','1.2.3.255'),array('bad','1.2.3.4')) as $range) { ip_check(phpbb_ip_ban_range($range[0],$range[1])===false,'Invalid/reversed/oversized range refused'); $cases++; }
echo 'IP ban parser/matcher/formatter: '.$cases." checks passed.\n";
