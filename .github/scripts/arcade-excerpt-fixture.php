<?php
require __DIR__.'/arcade-summary-fixture.php';
as_function($as_app.'includes/functions.php','phpbb_text_excerpt');
as_function($as_app.'activity.php','arcade_output_html');
$ae_source=file_get_contents($as_app.'activity.php');
as_check(preg_match_all('/\$last_game_desc = phpbb_text_excerpt\(\$catrows\[(?:\$i|0)\]\[\x27game_desc\x27\], 25\);/',$ae_source,$ae_prefixes)===3,'Exact three current Arcade caption paths');
as_check(preg_match_all('/\$last_played(?:_all)? = [^\r\n]*arcade_output_html\(\$last_game_desc\);/',$ae_source,$ae_links)===3,'Actual three last-played link expressions');
function ae_render($site,$value)
{
 global $ae_prefixes,$ae_links;$i=0;$filename='activity.php';$catrows=array(array('cat_id'=>7,'game_id'=>9,'game_desc'=>$value));
 eval($ae_prefixes[0][$site]);eval($ae_links[0][$site]);return $site===2?$last_played_all:$last_played;
}
function ae_check($html,$expected,$site)
{
 as_check(preg_match('/^<a href="([^"]*)" class="gensmall">([^<]*)$/s',$html,$m)===1,'Single safe last-game link prefix');
 as_check(html_entity_decode($m[2],ENT_QUOTES,'UTF-8')===$expected&&preg_match('//u',$html)===1,'Exact visible Unicode caption, raw literals and one output-escaping pass');
 as_check($m[1]==='activity.php?mode=cat&amp;cat_id='.($site===2?'0':'7').'#9','Original category/game target preserved');
}
