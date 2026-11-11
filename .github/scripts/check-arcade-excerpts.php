<?php
require __DIR__.'/arcade-excerpt-fixture.php';$count=0;
$vectors=array('',null,'0','Raw &amp; caption',"A\\B &#39; '",'Grüße 😀',str_repeat('ä',13),str_repeat('a',22).'😀Z',str_repeat('😀',7),str_repeat('ä',25),str_repeat('😀',25),str_repeat('x',25),str_repeat('ä',26),str_repeat('😀',26),str_repeat('x',26),str_repeat('x',23).'&<script>x</script>',str_repeat('x',23).'&amp;',str_repeat('x',23)."'\" ",str_repeat('x',24)."e\xcc\x81Z","invalid\xff");
foreach($vectors as$raw){$plain=$raw===null||$raw===''?'fixture_game':(string)$raw;$parts=preg_match('//u',$plain)===1?preg_split('//u',$plain,-1,PREG_SPLIT_NO_EMPTY):false;$expected=$parts===false?'':(count($parts)>25?implode('',array_slice($parts,0,25)).'...':$plain);
 for($site=0;$site<3;$site++){ae_check(ae_render($site,$raw),$expected,$site);$count++;}}
echo 'Arcade excerpts: '.$count." actual caption/link cases PASS; complete UTF-8 prefixes, literal entities/backslashes and stable targets.\n";
