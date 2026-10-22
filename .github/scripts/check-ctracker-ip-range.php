<?php
// Actual helper, documentation addresses only. No DB/HTTP/DNS or live history.
define('IN_PHPBB',true);
require dirname(dirname(__DIR__)).'/phpBB2/ctracker/classes/class_ct_userfunctions.php';
function ct_range_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class CtRangeNoDatabase {var $calls=0;function __call($name,$arguments){$this->calls++;throw new RuntimeException('IP warning must not change database/history');}}
$db=new CtRangeNoDatabase();$functions=new ct_userfunctions();$cases=0;
$HTTP_SERVER_VARS=array('REMOTE_ADDR'=>'203.0.113.9','HTTP_X_FORWARDED_FOR'=>'198.51.100.9');$server_before=$HTTP_SERVER_VARS;
foreach(array(
 array('192.0.2.4','4:192.0','192.0.x.x'),
 array('::ffff:192.0.2.4','4:192.0','192.0.x.x'),
 array('::FFFF:C000:0204','4:192.0','192.0.x.x'),
 array('0000:0000:0000:0000:0000:ffff:c000:0204','4:192.0','192.0.x.x'),
 array(' ::ffff:198.51.100.9 ','4:198.51','198.51.x.x'),
 array('::ffff:127.0.0.1','4:127.0','127.0.x.x'),
 array('10.20.30.40','4:10.20','10.20.x.x'),
 array('::ffff:a14:1e28','4:10.20','10.20.x.x'),
 array('2001:db8:1::1','6:20010db80001','2001:db8:1::/48'),
 array('2001:0DB8:0001:0000:0000:0000:0000:0001','6:20010db80001','2001:db8:1::/48'),
 array('::1','6:000000000000','0:0:0::/48'),
 array('::192.0.2.4','6:000000000000','0:0:0::/48'),
 array('::fffe:192.0.2.4','6:000000000000','0:0:0::/48'),
 array('64:ff9b::192.0.2.4','6:0064ff9b0000','64:ff9b:0::/48'),
 array('0.0.1.2','4:0.0','0.0.x.x')
)as$range){ct_range_check($functions->ip_range($range[0])===array('key'=>$range[1],'label'=>$range[2]),'Exact canonical network/partial label for '.$range[0]);$cases++;}
foreach(array('0.0.0.0','::ffff:0.0.0.0','::ffff:0:0',' 0.0.0.0 ','','invalid','999.0.2.4','::ffff:999.0.2.4','host.example.invalid',null,array('192.0.2.4'),new stdClass(),true,false,2130706433)as$invalid){ct_range_check($functions->ip_range($invalid)===false,'Unknown/invalid network is not a login change');$cases++;}
foreach(array('%s -> %s','Netz %s statt %s')as$format){
 $lang=array('ctracker_ipwarn_chng'=>$format);
 foreach(array(
  array('::ffff:192.0.2.4','::ffff:198.51.100.9','198.51.x.x','192.0.x.x'),
  array('::ffff:192.0.2.4','192.0.2.4',null,null),
  array('192.0.2.4','::ffff:192.0.2.4',null,null),
  array('::FFFF:C000:0204','192.0.2.4',null,null),
  array('::ffff:192.0.2.4','::ffff:192.0.99.1',null,null),
  array('::ffff:192.0.2.4','198.51.100.9','198.51.x.x','192.0.x.x'),
  array('::ffff:0.0.0.0','198.51.100.9',null,null),
  array('198.51.100.9','::ffff:0:0',null,null),
  array('0.0.0.0','198.51.100.9',null,null),
  array('2001:db8:1::1','2001:db8:1:ffff::9',null,null),
  array('2001:db8:1::1','2001:db8:2::1','2001:db8:2::/48','2001:db8:1::/48'),
  array('2001:db8:1::1','192.0.2.4','192.0.x.x','2001:db8:1::/48'),
  array(array('192.0.2.4'),'198.51.100.9',null,null),
  array(null,'198.51.100.9',null,null)
 )as$warning){
  $userdata=array('user_id'=>42,'ct_last_ip'=>$warning[0],'ct_last_used_ip'=>$warning[1]);$before=$userdata;
  $expected=$warning[2]===null?'allclear':sprintf($format,$warning[2],$warning[3]);
  ct_range_check($functions->check_ip_range()===$expected,'Equivalent spelling/real network change/unknown warning boundary');
  ct_range_check($userdata===$before&&$HTTP_SERVER_VARS===$server_before&&$db->calls===0,'History, verified IP/proxy data and database remain untouched');$cases++;
 }
}
echo 'CrackerTracker IP ranges: '.$cases." actual mapped/native/unknown/partial-label/localized/nonmutating cases passed; no IO.\n";
