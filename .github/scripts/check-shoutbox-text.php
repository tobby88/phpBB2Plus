<?php
require __DIR__.'/shoutbox-text-fixture.php';
class SxReadOnly
{
	function sql_query($sql)
	{
		if (trim($sql) === 'SELECT word, replacement FROM fixture_words') { return (object) array('rows'=>array(array('word'=>'badword','replacement'=>'censored'))); }
		if (preg_match('/^SELECT\s+word,\s*replacement\s+FROM\s+fixture_words\s*$/D',trim($sql))) { return (object) array('rows'=>array(array('word'=>'badword','replacement'=>'censored'))); }
		if (trim($sql) === 'SELECT * FROM fixture_smilies') { return (object) array('rows'=>array(array('code'=>':)','smile_url'=>'icon_smile.gif','emoticon'=>'Synthetic smile'))); }
		throw new RuntimeException('Pure preview may read only its synthetic censor/smilie data');
	}
	function sql_fetchrow($result) { return $result->rows ? array_shift($result->rows) : false; }
	function sql_fetchrowset($result) { $rows=$result->rows; $result->rows=array(); return $rows; }
	function sql_freeresult($result) {}
}
$db = new SxReadOnly();
foreach (array(array('0','0'),array(" Grüße 😀 ",'Grüße 😀'),array(str_repeat('a',15999).'ü',str_repeat('a',15999)),array(str_repeat('a',15998).'😀',str_repeat('a',15998)),array(str_repeat('😀',5000),str_repeat('😀',4000)),array("O'Reilly C:\\temp","O'Reilly C:\\temp"),array("bad\xff",false),array(array('bad'),false),array('', '')) as $case)
{
	$value = PHPBB_LEGACY_REQUEST_ESCAPED ? phpbb_addslashes_recursive($case[0]) : $case[0]; $text = phpbb_shoutbox_text($value);
	sx_check($text === $case[1], 'Exact canonical input and whole UTF-8 byte prefix, case '.$sx_cases.'; actual '.var_export(is_string($text)?array(strlen($text),bin2hex(substr($text,-8))):$text,true)); if ($text !== false) { sx_check(strlen($text)<=16000 && preg_match('//u',$text)===1, 'Complete bounded UTF-8 text'); } $sx_cases++;
}
foreach(array(array('😀',0,''),array('😀',1,''),array('😀',2,''),array('😀',3,''),array('😀',4,'😀'),array('ü',1,''),array('ü',2,'ü'))as$case)
{
	sx_check(phpbb_shoutbox_text($case[0],$case[1])===$case[2],'Small byte budgets preserve a valid empty prefix on legacy and current PHP');$sx_cases++;
}
foreach (array('english','german') as $locale)
{
	sx_language($locale);
	foreach (array(array(0,0,0),array(1,0,0),array(0,1,0),array(1,1,1)) as $flags)
	{
		foreach (array('0',"Grüße 😀 O'Reilly \"quoted\" C:\\temp\nnext",'<b>HTML</b> [b]BBCode[/b] badword :)','Literal &lt;b&gt; &#039;') as $raw)
		{
			$r = sx_run('shoutbox_max.php',$raw,'preview',$flags);
			sx_check(!$r['error'] && !$r['rejected'] && isset($r['vars']['POST_PREVIEW_BOX']), 'Actual full preview is generated, including zero');
			$prepared = phpbb_shoutbox_prepare_message($r['text'],$flags[0],$flags[1],$flags[2],$r['uid']);
			$rendered = $prepared === false ? false : sx_render_stored(stripslashes($prepared),$r['uid'],$flags);
			sx_check($prepared !== false && $r['vars']['MESSAGE'] === $rendered, 'Actual preview equals stored-message display pipeline: '.var_export(array($locale,$flags,$raw,$r['vars']['MESSAGE'],$rendered),true));
			sx_check(html_entity_decode($r['vars']['MESSAGE_MAX'],ENT_QUOTES,'UTF-8') === $r['text'], 'Preview form preserves exact canonical Unicode/quotes/slashes/entities');
			sx_check($r['vars']['USERNAME'] === htmlspecialchars($r['username'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') && strpos($r['vars']['USERNAME'],'<b>')===false, 'Preview uses escaped exact session identity'); $sx_cases++;
		}
	}
	foreach (array(str_repeat('&',16000),str_repeat('"',16000)) as $raw)
	{
		$r = sx_run('shoutbox_max.php',$raw,'preview'); sx_check($r['error'] && $r['error_msg'] === $lang['Shoutbox_format_failed'] && !isset($r['vars']['POST_PREVIEW_BOX']), 'Encoded TEXT overflow is controlled before a misleading preview');
		sx_check(html_entity_decode($r['vars']['MESSAGE_MAX'],ENT_QUOTES,'UTF-8') === $raw, 'Oversize formatted input retained for correction'); $sx_cases++;
	}
	foreach (array('shoutbox.php','shoutbox_max.php') as $route)
	{
		$r = sx_run($route,'0','refresh'); $field = $route==='shoutbox.php'?'MESSAGE':'MESSAGE_MAX'; sx_check($r['vars'][$field] === '0','Actual refresh retains a zero message'); $sx_cases++;
		$r = sx_run($route,array('bad'),'refresh'); sx_check($r['error'] && $r['error_msg']===$lang['Shoutbox_invalid_text'],'Invalid message shape has localized controlled feedback'); $sx_cases++;
	}
}
$limit=ini_get('pcre.backtrack_limit');$jit=ini_get('pcre.jit');sx_check(preg_match('//u','[b]text[/b]')===1,'Resource-failure input starts as valid UTF-8');ini_set('pcre.jit','0');ini_set('pcre.backtrack_limit','1');
sx_check(preg_match('//u','[b]text[/b]')===false && phpbb_shoutbox_prepare_message('[b]text[/b]',0,1,0,'1234567890')===false,'Actual PCRE validation failure returns a controlled failure, never partial output');
ini_set('pcre.backtrack_limit',$limit);if($jit!==false){ini_set('pcre.jit',$jit);}$sx_cases++;
restore_error_handler();echo 'Shoutbox text ',PHPBB_LEGACY_REQUEST_ESCAPED?'legacy':'raw',': ',$sx_cases,' cases, ',$sx_checks," assertions PASS; whole UTF-8 byte limits, actual preview/refresh/templates, stored-render parity, localized errors and session names; no writes.\n";
