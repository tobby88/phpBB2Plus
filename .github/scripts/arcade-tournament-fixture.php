<?php
require __DIR__.'/arcade-summary-fixture.php';
as_function($as_app.'arcade_tournament.php','arcade_tournament_player_html');
$at_source=file_get_contents($as_app.'arcade_tournament.php');$at_bindings=array();
foreach(preg_split('/\r?\n/',$at_source)as$line){
 if(strpos($line,'$message .= sprintf($lang[\'tour_msg_winner\']')!==false){$at_bindings[]=array('message',trim($line));}
 elseif(preg_match('/^\s*\'NAME\' => arcade_tournament_player_html\(\$tour_players/',$line)){$at_bindings[]=array('array',trim($line));}
 elseif(strpos($line,'arcade_tournament_player_html(')!==false&&preg_match('/\$(played_players|highest_scorer|last_5_champ_name)\s*\.?=/',$line,$m)){$at_bindings[]=array($m[1],trim($line));}
}
as_check(count($at_bindings)===5,'All five actual tournament label/link bindings');
preg_match_all('/\$sql = "SELECT p\.\*, u\.username FROM " \. iNA_TOUR_PLAY \. " AS p.*?\$tour_id;/s',$at_source,$at_queries);as_check(count($at_queries[0])===2,'Actual finish and view participant JOINs');
function at_render($id,$name){
 global $arcade,$at_bindings,$lang,$phpEx;$out=array();$winner_id=$player_id=$top_player_id=$champion_id=$id;$i=0;$tour_players=array(array('user_id'=>$id,'username'=>$name));
 foreach($at_bindings as$binding){$message=$played_players=$highest_scorer=$last_5_champ_name='';if($binding[0]==='array'){$values=eval('return array('.$binding[1].');');$html=$values['NAME'];}else{eval($binding[1]);$html=${$binding[0]};}$out[$binding[0]]=$html;}return $out;
}
function at_check_output($out,$raw,$id,$linked=true){
 global $lang;foreach($out as$kind=>$html){$expected=$kind==='message'?trim(html_entity_decode(strip_tags(sprintf($lang['tour_msg_winner'],htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))),ENT_QUOTES,'UTF-8')):$raw;as_check(trim(html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8'))===$expected,'Actual tournament byline has the exact safe account/placeholder label');$links=$kind==='message'||!$linked?0:1;as_check(substr_count($html,'<a ')===$links&&strpos($html,'<b>ü</b>')===false,'Only an extant registered account receives a link; names never inject markup');if($links){preg_match('/href="([^"]+)"/',$html,$m);parse_str(parse_url(html_entity_decode($m[1],ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$q);as_check($q['mode']==='viewprofile'&&$q['u']===(string)$id&&$q['sid']==='tournament_fixture','Actual profile targets and current request SID stay intact');}if($kind==='last_5_champ_name'){as_check(substr_count($html,'images/crown.gif')===2,'Champion decorations remain intact');}}
}
