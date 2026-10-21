<?php
$root=dirname(dirname(__DIR__));$source=file_get_contents($root.'/phpBB2/includes/functions_kb.php');
foreach(array('kb_stats_page_size','kb_stats_page_start')as$name){if(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$match)!==1){throw new RuntimeException('Actual statistics window helper '.$name);}eval($match[0]);}
function ks_page_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$cases=0;
foreach(array(array('7',7),array(20,20),array('0',1),array('1001',1000),array('99999999',1000),array('00000007',7),array('bad',20),array('7tail',20),array(array(),20),array(true,20),array(null,20))as$case){ks_page_check(kb_stats_page_size($case[0])===$case[1],'Complete bounded page-size contract');$cases++;}
foreach(array(0,1,2,37,1001,2147483647,PHP_INT_MAX)as$total){foreach(array(1,7,20,1000)as$size){foreach(array(0,'1','8','99999999999999999999',(string)PHP_INT_MAX,array('7'),null,'7tail',-1)as$start){
 $out=kb_stats_page_start($start,$size,$total);ks_page_check(is_int($out)&&$out>=0&&($total===0?$out===0:($out<$total&&$out%$size===0)),'Every integer boundary returns a real in-range page, no float overflow or partial numeric parsing');
 if($start==='99999999999999999999'){$last=$total?($total-1)-(($total-1)%$size):0;ks_page_check($out===$last,'Huge offset reaches last valid page without narrowing counts');}$cases++;
}}}
ks_page_check(kb_stats_page_start('8',7,37)===7&&kb_stats_page_start('0007',7,37)===7&&kb_stats_page_start('7tail',7,37)===0,'Real page alignment, leading zeros and malformed offsets');$cases++;
$a=strpos($source,'function get_kb_stats(');$stats=substr($source,$a);
ks_page_check($a!==false&&strpos($stats,'SELECT *')===false&&strpos($stats,'a.article_body')===false&&strpos($stats,"' LIMIT '.\$actual_start.','.\$per_page")!==false,'Summary projection and bounded actual query, not an in-memory slice');
ks_page_check(strpos($stats,'CAST(a.article_date AS UNSIGNED) DESC,a.article_id DESC')!==false,'Numeric timestamp order with stable ID ties');
$controller=file_get_contents($root.'/phpBB2/includes/kb_stats.php');$category=file_get_contents($root.'/phpBB2/includes/kb_cat.php');$template=file_get_contents($root.'/phpBB2/templates/fisubsilversh/kb_stats_body.tpl');
ks_page_check(strpos($controller,'mode=stats&amp;stats=')!==false&&strpos($controller,'$stats_start')!==false&&strpos($template,'{PAGINATION}')!==false&&strpos($template,'{PAGE_NUMBER}')!==false,'Actual controller and standard template expose complete statistics navigation');
ks_page_check(strpos($category,"(!\$is_admin ? ' AND approved = 1' : '')")!==false,'Public category count honors the same visibility as article rows');
echo 'KB statistics pagination: '.$cases." actual integer-boundary/window cases and query/controller/template wiring passed. No database/network.\n";
