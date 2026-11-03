<?php
require __DIR__.'/arcade-summary-fixture.php';
$az_source=file_get_contents($as_app.'activity.php');$az_bindings=array();
foreach(preg_split('/\r?\n/',$az_source)as$line){if(preg_match('/^\s*\$(display_at_score|display_score|best_score|best_at_score)\s*=.*->convert_score\(.*;$/',$line,$m)){$az_bindings[]=array($m[1],trim($line));}}
as_check(count($az_bindings)===6,'All six actual controller score bindings');$arcade=new arcade();
function az_input($value){return array('current'=>array('player_id'=>7,'username'=>'Fixture','score'=>$value),'alltime'=>array('player_id'=>7,'username'=>'Fixture','score'=>$value),'row'=>array('score'=>$value),'rows'=>array(array('score'=>$value)),'games'=>array(array('score'=>$value,'at_score'=>$value)));}
function az_render($input){
 global $arcade,$az_bindings,$lang;$score_info=$input['current'];$score_at_info=$input['alltime'];$row=$input['row'];$rows=$input['rows'];$game_rows=$input['games'];$i=0;$out=array();
 foreach($az_bindings as$binding){eval($binding[1]);$out[]=${$binding[0]};}return $out;
}
function az_expected($value){return array($value,$value,$value,$value,$value,'<br /><br />'.$value);}
function az_sql($needle){global $az_source;$a=strpos($az_source,$needle);$ends=array();if($a!==false){foreach(array('if(!$result =','if( !$result =')as$end){$p=strpos($az_source,$end,$a);if($p!==false){$ends[]=$p;}}}$b=$ends?min($ends):false;as_check($a!==false&&$b>$a,'Actual complete SELECT assignment');return substr($az_source,$a,$b-$a);}
