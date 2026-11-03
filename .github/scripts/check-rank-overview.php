<?php
// Actual rank queries, loops and template bindings; synthetic SELECT responses.
define('IN_PHPBB',true);define('RANKS_TABLE','fixture_ranks');define('USERS_TABLE','fixture_users');define('POST_USERS_URL','u');
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$app=dirname(dirname(__DIR__)).'/phpBB2/';require$app.'includes/php_compat.php';require$app.'includes/functions.php';
function ro_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function append_sid($url){return$url;}
class RankOverviewSelectFixture {
 var $standard;var $special;var $members;var $queries=array();
 function __construct($standard,$special,$members){$this->standard=$standard;$this->special=$special;$this->members=$members;}
 function sql_query($sql){
  $this->queries[]=$sql;
  if(strpos($sql,'SELECT * FROM fixture_ranks WHERE rank_special = 1 ')===0){$rows=$this->special;}
  elseif(strpos($sql,'SELECT * FROM fixture_ranks WHERE rank_special <> 1 ')===0){$rows=$this->standard;}
  elseif(strpos($sql,'SELECT * FROM fixture_users WHERE user_active = 1 AND ')===0){
   ro_check(preg_match('/user_rank = ([0-9]+)/',$sql,$m)===1,'Actual active rank membership query');$key='special:'.$m[1];
   if($m[1]==='0'){ro_check(preg_match('/user_posts >= ([0-9]+)/',$sql,$n)===1,'Actual standard rank lower bound');$key='standard:'.$n[1];}
   $rows=$this->members[$key];if(preg_match('/LIMIT 0, ([0-9]+)/',$sql,$limit)){$rows=array_slice($rows,0,(int)$limit[1]);}
  }else{throw new RuntimeException('Unexpected database operation');}
  return(object)array('rows'=>$rows);
 }
 function sql_fetchrow($result){return$result->rows?array_shift($result->rows):false;}function sql_numrows($result){return count($result->rows);}
}
class RankOverviewTemplateFixture {
 var $blocks=array();var $vars=array();var $files=array();var $parsed=array();
 function assign_block_vars($block,$values){$this->blocks[$block][]=$values;}function assign_vars($values){$this->vars=array_merge($this->vars,$values);}
 function set_filenames($files){$this->files=$files;}function pparse($handle){$this->parsed[]=$handle;}
}
function ro_list($html,$users,$limit,$title,$pcp){
 $total=count($users);ro_check(is_string($html),'Every rank has an initialized list');
 ro_check($total?strpos($html,'('.$total.') ')===0:$html==='','Actual member counts and empty rank list');
 preg_match_all('~<a href="([^"]+)" class="gensmall">(.*?)</a>~s',$html,$links);
 $visible=$limit===0?0:($limit<0?$total:min($total,$limit));$more=$limit>0&&$total>$limit;
 ro_check(count($links[1])===$visible+($more&&$pcp?1:0),'Hidden, bounded and unlimited member-list lengths');
 for($i=0;$i<$visible;$i++){$query=array();parse_str(parse_url(html_entity_decode($links[1][$i],ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);
  ro_check($query===array('mode'=>'viewprofile','u'=>(string)$users[$i]['user_id']),'Actual member link targets the correct account');
  ro_check($links[2][$i]===htmlspecialchars($users[$i]['username'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false),'Actual member name is UTF-8 safe and escaped once');
 }
 if($more&&$pcp){$query=array();parse_str(parse_url(html_entity_decode($links[1][$visible],ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);ro_check($query===array('mode'=>'buddy','sub'=>'memberlist','filter'=>'user_rank_title','comp'=>'eq','fvalue'=>$title),'Optional legacy link keeps the complete encoded title as one value');}
 elseif($more){ro_check(substr($html,-3)==='...','Ordinary list keeps the overflow indication');}
}
$checks=0;$cases=0;$source=file_get_contents($app.'ranks.php');
foreach(array('get_rank_title','phpbb_rank_overview_user_link','phpbb_rank_overview_more_link','phpbb_rank_overview_image')as$name){ro_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$match)===1,'Actual rank output helper');eval($match[0]);}
$a=strpos($source,'$spe_ranks = array();');$b=strpos($source,'// set the page title',$a);ro_check($a!==false&&$b>$a,'Actual special and standard rank selection loops');$selection=substr($source,$a,$b-$a);
$a=strpos($source,'$template->set_filenames(array(');$b=strrpos($source,"include(\$phpbb_root_path . 'includes/page_tail.");ro_check($a!==false&&$b>$a,'Actual rank template bindings');$render=substr($source,$a,$b-$a);
$standard=array(array('rank_id'=>1,'rank_min'=>0,'rank_title'=>'Legacy &amp; rank','rank_image'=>'normal.gif'),array('rank_id'=>2,'rank_min'=>10,'rank_title'=>'Empty <&>','rank_image'=>''),array('rank_id'=>3,'rank_min'=>20,'rank_title'=>'Neutral <&>|Male "&"|Female "<"','rank_image'=>'bad".gif'));
$special=array(array('rank_id'=>5,'rank_min'=>-1,'rank_title'=>'Special <&>','rank_image'=>'special.GIF'),array('rank_id'=>6,'rank_min'=>-1,'rank_title'=>'Empty special','rank_image'=>'../bad.gif'));
$members=array('standard:0'=>array(array('user_id'=>7,'username'=>'Current <&>'),array('user_id'=>8,'username'=>'Grüße 😀 &amp;')),'standard:10'=>array(),'standard:20'=>array(array('user_id'=>9,'username'=>'Alpha'),array('user_id'=>10,'username'=>'Beta'),array('user_id'=>11,'username'=>'Gamma')),'special:5'=>array(array('user_id'=>12,'username'=>'Special & Name'),array('user_id'=>13,'username'=>'Noch ein Nutzer')),'special:6'=>array());
$phpEx='php';$images=array('rank_path'=>'images/ranks/');
foreach(array('english','german')as$locale){$lang=array();$lang_extend_admin=false;require$app.'language/lang_'.$locale.'/lang_main.php';require$app.'language/lang_'.$locale.'/lang_extend_ranks.php';
 foreach(array(array(false,false),array(true,false),array(false,true))as$flags){$rank_extended=$flags[0];$profilcp=$flags[1];
  foreach(array(0,1,10,-1)as$std_rank_max_users){foreach(array(0,1,-1)as$spe_rank_max_users){
   $board_config=array('hide_removed_author_names'=>1);$userdata=array('user_id'=>3,'session_logged_in'=>true);$db=new RankOverviewSelectFixture($standard,$special,$members);$template=new RankOverviewTemplateFixture();
   $snapshot=serialize(array($standard,$special,$members,$board_config,$userdata,$lang));eval($selection);eval($render);
   ro_check(count($ranks)===3&&count($spe_ranks)===2&&$template->parsed===array('body'),'Actual complete rank overview renders once');
   foreach(array(array($ranks,'ranks',$std_rank_max_users,'standard:'),array($spe_ranks,'spe_ranks',$spe_rank_max_users,'special:'))as$group){
    foreach($group[0]as$i=>$row){$users=$members[$group[3].($group[1]==='ranks'?$row['rank_min']:$row['rank_id'])];ro_check($row['user_number']===count($users),'Actual membership count unchanged');ro_list($row['users_list'],$users,$group[2],$row['rank_title'],$profilcp);
     $bound=$template->blocks[$group[1]][$i];$formatted=$rank_extended||$profilcp;$parts=explode('|',$row['rank_title']);
     $visible=$formatted?(!empty($parts[1])?$lang['Male'].': '.html_entity_decode($parts[1],ENT_QUOTES,'UTF-8'):'').(!empty($parts[2])?$lang['Female'].': '.html_entity_decode($parts[2],ENT_QUOTES,'UTF-8'):'').$lang['No_gender_specify'].': '.html_entity_decode($parts[0],ENT_QUOTES,'UTF-8'):html_entity_decode($row['rank_title'],ENT_QUOTES,'UTF-8');
     ro_check(html_entity_decode(strip_tags($bound['RANK_TITLE']),ENT_QUOTES,'UTF-8')===$visible,'Plain and optional gender rank labels remain readable');
     ro_check(strpos(preg_replace('~</?b>|<br\s*/?>~','',$bound['RANK_TITLE']),'<')===false,'Stored rank text never introduces markup');
     $image=($group[1]==='ranks'&&$i===0)?'normal.gif':($group[1]==='spe_ranks'&&$i===0?'special.GIF':'');
     ro_check($image===''?$bound['RANK_IMAGE']==='':strpos($bound['RANK_IMAGE'],'src="images/ranks/'.$image.'"')!==false,'Actual rank image uses only a safe stored filename');
    }
   }
   ro_check(serialize(array($standard,$special,$members,$board_config,$userdata,$lang))===$snapshot,'Overview preserves source ranks, members, configuration and translations');$cases++;
  }}
 }
}
restore_error_handler();echo 'Rank overview: ',$cases,' actual EN/DE render cases, ',$checks," assertions PASS; empty/hidden/bounded/unlimited lists, counts, UTF-8 names, images, titles and optional legacy formatting.\n";
