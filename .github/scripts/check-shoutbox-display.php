<?php
// Actual stored-message output and preview branches, with only synthetic reads.
require __DIR__.'/shoutbox-text-fixture.php';
class SdReadOnly
{
	function sql_query($sql)
	{
		if (preg_match('/^SELECT\s+word,\s*replacement\s+FROM\s+fixture_words\s*$/D', trim($sql))) { return (object) array('rows'=>array(array('word'=>'badword','replacement'=>'censored'))); }
		if (trim($sql) === 'SELECT * FROM fixture_smilies') { return (object) array('rows'=>array(array('code'=>':)','smile_url'=>'icon_smile.gif','emoticon'=>'Synthetic smile'))); }
		throw new RuntimeException('Display fixture permits only synthetic word/smilie reads');
	}
	function sql_fetchrowset($result) { return $result->rows; }
	function sql_fetchrow($result) { return $result->rows ? array_shift($result->rows) : false; }
	function sql_freeresult($result) {}
}
$db = new SdReadOnly(); $template = new SxTemplate(); $sd_sources = $sd_render = $sd_censor = array();
foreach (array('shoutbox_max.php','shoutbox_view.php') as $route)
{
	$sd_sources[$route] = file_get_contents($sx_app.$route);
	sx_check(strpos($sd_sources[$route], "'includes/functions_shoutbox.'") !== false, 'Each real output entry loads the shared renderer');
	sx_check(preg_match('/\$shout = phpbb_shoutbox_render\([^;]+;/', $sd_sources[$route], $part) === 1, 'Actual complete stored-render call required'); $sd_render[$route] = $part[0];
	sx_check(preg_match('/\$shout = \(! \$shout_row\[\x27shout_active\x27\]\)[^;]+;/', $sd_sources[$route], $part) === 1, 'Actual censor boundary required'); $sd_censor[$route] = $part[0];
}
$sd_input = sx_part($sd_sources['shoutbox_max.php'], '$requested_highlight =', '$sql = "SELECT *');
function sd_output($route, $row, $query, $moderator = false)
{
	global $sd_input,$sd_censor,$sd_render,$board_config,$theme,$lang;
	$shout_row = $row; $is_auth = array('auth_mod'=>$moderator); $smilies_on = true;
	$_GET = array('highlight'=>$query); if (PHPBB_LEGACY_REQUEST_ESCAPED) { $_GET = phpbb_addslashes_recursive($_GET); }
	eval($sd_input); eval($sd_censor[$route]); eval($sd_render[$route]);
	return array('html'=>$shout,'query'=>$requested_highlight,'navigation'=>$highlight);
}
$samples = array(
	array('0', '', 0, 0), array('https://example.test/path','',1,0),
	array('[b]https://example.test/path[/b]','',1,0), array('[code]https://example.test/path[/code]','',0,0),
	array('[url=https://example.test/path]hello[/url]','hello',1,1),
	array('A & B','amp',0,0), array('A & B','&',0,1), array('foo* foobar','foo*',0,2),
	array('Äpfel äpfel','ÄPFEL',0,2), array('Grüße ü','ü',0,1), array("cafe\xCC\x81s","cafe\xCC\x81*",0,1),
	array("hello\xC2\xA0world", "hello\xC2\xA0world",0,2), array("O'Reilly C:\\temp", "O'Reilly C:\\temp",0,2),
	array('[b]hello[/b]', 'b',0,0), array('[b]hello[/b]', 'hello',0,1),
	array('badword', 'badword',0,0), array('badword','censored',0,1),
	array('hello',array('hello'),0,0), array('hello',str_repeat('x',1025),0,0), array('hello',"bad\xff",0,0),
	array('hello',"hello\0",0,0), array('hello','***',0,0), array("Line one\nLine two",'',0,0)
);
foreach (array('english','german') as $locale)
{
	sx_language($locale);
	foreach ($samples as $sample)
	{
		$flags = array(0,1,0); $preview = sx_run('shoutbox_max.php',$sample[0],'preview',$flags);
		sx_check(!$preview['error'], 'Actual preview prepares complete source');
		$prepared = phpbb_shoutbox_prepare_message($sample[0],0,1,0,$preview['uid']);
		$row = array('shout_text'=>stripslashes($prepared),'shout_bbcode_uid'=>$preview['uid'],'enable_bbcode'=>1,'enable_smilies'=>0,'shout_active'=>0,'user_allowsmile'=>1);
		$theme = array('fontcolor3'=>'ff0000'); $board_config['allow_smilies'] = true;
		$full = sd_output('shoutbox_max.php',$row,$sample[1]); $plain = sd_output('shoutbox_max.php',$row,''); $compact = sd_output('shoutbox_view.php',$row,'');
		sx_check($plain['html'] === $compact['html'] && $plain['html'] === $preview['vars']['MESSAGE'], 'Actual compact, full and preview markup agree before highlighting');
		sx_check(substr_count($full['html'],'<a ') === $sample[2], 'Correct generated link count for sample '.$sx_cases);
		sx_check(substr_count($full['html'],'<b style="color:#ff0000">') === $sample[3], 'Highlight complete visible words/entities only, sample '.$sx_cases);
		$unmarked = str_replace(array('<b style="color:#ff0000">','</b>'), '', $full['html']);
		sx_check(html_entity_decode(strip_tags($unmarked),ENT_QUOTES,'UTF-8') === html_entity_decode(strip_tags($plain['html']),ENT_QUOTES,'UTF-8'), 'Highlighting preserves visible text');
		sx_check(urldecode($full['navigation']) === $full['query'], 'Actual pagination query round trip');
		if (strpos($full['html'],'href=') !== false) { sx_check(strpos($full['html'],'href="https://example.test/path"') !== false, 'Highlighting leaves target URL unchanged'); }
		$sx_cases++;
	}
	foreach (array(false,true) as $smilies)
	{
		$preview = sx_run('shoutbox_max.php',"[b]Bold[/b] :)\n0",'preview',array(0,1,(int)$smilies));
		$row = array('shout_text'=>stripslashes(phpbb_shoutbox_prepare_message("[b]Bold[/b] :)\n0",0,1,(int)$smilies,$preview['uid'])),'shout_bbcode_uid'=>$preview['uid'],'enable_bbcode'=>1,'enable_smilies'=>(int)$smilies,'shout_active'=>0,'user_allowsmile'=>1);
		$board_config['allow_smilies'] = true; $full=sd_output('shoutbox_max.php',$row,''); $compact=sd_output('shoutbox_view.php',$row,'');
		sx_check($full['html'] === $compact['html'] && $full['html'] === $preview['vars']['MESSAGE'], 'Smilies/newlines match the real preview');
		sx_check((strpos($full['html'],'icon_smile.gif') !== false) === $smilies && strpos($full['html'],'<br />0') !== false, 'Stored smile toggle and newline remain effective'); $sx_cases++;
	}
	$row['shout_text'] = 'Hidden original'; $row['shout_active'] = 4;
	foreach (array('shoutbox_max.php','shoutbox_view.php') as $route) { $r=sd_output($route,$row,''); sx_check(strpos($r['html'],'Hidden original') === false && strpos($r['html'],$lang['Shout_censor']) !== false, 'Nonmoderators cannot recover censored text through display or highlight'); $sx_cases++; }
	$r=sd_output('shoutbox_max.php',$row,'',true); sx_check(strpos($r['html'],'Hidden original') !== false, 'Existing full moderator censor review retained'); $sx_cases++;
}
restore_error_handler();echo 'Shoutbox display ',PHPBB_LEGACY_REQUEST_ESCAPED?'legacy':'raw',': ',$sx_cases,' cases, ',$sx_checks," assertions PASS; actual compact/full/preview branches, links, code, censoring, whole entities and Unicode highlights; synthetic reads only.\n";
