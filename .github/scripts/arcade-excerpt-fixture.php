<?php
require __DIR__.'/arcade-summary-fixture.php';
as_function($as_app.'includes/functions.php','phpbb_text_excerpt');
as_function($as_app.'activity.php','arcade_output_html');
$ae_source=file_get_contents($as_app.'activity.php');
as_check(preg_match_all('/\$last_played(?:_all)? = phpbb_arcade_last_play_link\(\$catrows\[(?:\$i|0)\],[^;]+;/',$ae_source,$ae_links)===3,'Actual three shared last-played caption/link expressions');
function ae_render($site,$value)
{
 global $ae_links;$i=0;$filename='activity.php';$userdata=array('user_level'=>0);$catrows=array(array('cat_id'=>7,'game_id'=>9,'game_name'=>'fixture_game','game_desc'=>$value,'last_game_cat_id'=>7,'last_game_avail'=>1));
 eval($ae_links[0][$site]);return $site===2?$last_played_all:$last_played;
}
function ae_check($html,$expected,$site)
{
 as_check(preg_match('/^<a href="([^"]*)" class="gensmall">([^<]*)<\/a>$/s',$html,$m)===1,'Single safe complete last-game link');
 as_check(html_entity_decode($m[2],ENT_QUOTES,'UTF-8')===$expected&&preg_match('//u',$html)===1,'Exact visible Unicode caption, raw literals and one output-escaping pass');
 as_check($m[1]==='activity.php?mode=cat&amp;cat_id=7#9','Every caption links the actual game category');
}
