<?php
// Actual topic input/render branches and production helper; no forum/SQL/session.
if (!isset($argv[1]))
{
    foreach (array('raw','legacy') as $mode)
    {
        $p=proc_open(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(__FILE__).' '.$mode,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));
        if(!is_resource($p)){throw new RuntimeException('Highlight fixture process unavailable');}
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($p)!==0||$err!==''){throw new RuntimeException('Highlight '.$mode.' fixture failed: '.$out.$err);}echo $out;
    }
    echo "Topic highlighting: both actual request-boundary modes PASS.\n";return;
}
define('IN_PHPBB',true);define('PHPBB_LEGACY_REQUEST_ESCAPED',$argv[1]==='legacy');
$app=dirname(dirname(__DIR__)).'/phpBB2/';require$app.'includes/functions_topic_highlight.php';
function th_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function th_fragment($source,$start,$end){$a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a);if($a===false||$b===false){throw new RuntimeException('Actual topic highlight branch missing');}return substr($source,$a,$b-$a);}
$source=file_get_contents($app.'viewtopic.php');
$input=th_fragment($source,'$requested_highlight =',"\n");
$prepare=th_fragment($source,'$highlight_match = phpbb_topic_highlight_pattern(', '$new_topic_url =');
$render=th_fragment($source,'if ($highlight_match)','// Replace naughty words');
$checks=0;$cases=0;$theme=array('fontcolor3'=>'ff0000');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$samples=array(
 array('hello','hello',1),array('foo*','foobar',1),array('*bar','foobar',1),array('**foo**','foobar',1),
 array('***','hello world',0),array('a+b','a+b',1),array('cat cat','cat',1),array('cat','concatenate cat',1),
 array('ÄPFEL','äpfel',1),array('ü','Grüße ü',1),array("cafe\xCC\x81*","cafe\xCC\x81s",1),
 array("hello\xC2\xA0world",'hello world',2),array("hello\nworld",'hello world',2),
 array("O'Reilly",'O&#039;Reilly',1),array('"quoted"','&quot;quoted&quot;',1),array('C:\\temp','C:\\temp',1),
 array('&','A &amp; B',1),array('amp','A &amp; B',0),array('<b>','&lt;b&gt;',1),
 array('hello','<a title="hello > world" href="/hello">hello</a>',1),
 array('hello','<!-- hello -->hello',1),array('hello',"<script>if (a < b) { x='hello'; }</script>hello",1),
 array('hello','<style>.hello{color:red}</style>hello',1),array('hello','<textarea>hello</textarea>hello',1),
 array('hello','<title>hello</title>hello',1),array('hello','<a title="hello',0),
 array('hello','<script/>hello</script>hello',1),array('hello','<xmp>hello</xmp>hello',1),
 array('hello','<iframe>hello</iframe>hello',1),array('hello','<plaintext>hello</plaintext>hello',0),
 array(array('hello'),'hello',0),array(null,'hello',0),array("bad\xff",'hello',0),array(str_repeat('x',1025),'hello',0),
 array("hello\0",'hello',0),array('', 'hello',0)
);
foreach($samples as$sample)
{
    $_GET=array('highlight'=>$sample[0]);if(PHPBB_LEGACY_REQUEST_ESCAPED){$_GET=phpbb_addslashes_recursive($_GET);}$HTTP_GET_VARS=$_GET;
    $message=$sample[1];eval($input);eval($prepare);eval($render);
    th_check(substr_count($message,'<b style="color:#ff0000">')===$sample[2],'Unexpected visible highlight count for case '.$cases);
    th_check(is_string($message)&&$message!=='','Highlighting erased a post');
    th_check(urldecode($highlight)===$requested_highlight,'Highlight navigation lost its raw query round trip');
    $expected=is_string($sample[0])&&strlen($sample[0])<=1024&&preg_match('//u',$sample[0])===1&&strpos($sample[0],"\0")===false?$sample[0]:'';
    th_check($requested_highlight===$expected,'Canonical input shape/UTF-8/legacy escape handling differs');
    if($sample[2]===0){th_check($message===$sample[1],'Unmatched or invalid query changed a post');}
    $cases++;
}
$pattern=phpbb_topic_highlight_pattern('hello');
$markup='<a title="hello > world" href="/hello">hello &amp; world</a>';
$actual=phpbb_topic_highlight_apply($markup,$pattern,'ff0000');
th_check(strpos($actual,'<a title="hello > world" href="/hello">')===0,'Markup and attribute bytes must be preserved');
th_check(html_entity_decode(strip_tags($actual),ENT_QUOTES|ENT_HTML5,'UTF-8')===html_entity_decode(strip_tags($markup),ENT_QUOTES|ENT_HTML5,'UTF-8'),'Highlighting changed visible post text');
th_check(strpos(phpbb_topic_highlight_apply('hello',$pattern,'" onmouseover="fixture()'),'color:#000000')!==false,'Highlight colors must not create markup');
$invalid="hello\xff";th_check(phpbb_topic_highlight_apply($invalid,$pattern,'ff0000')===$invalid,'Invalid stored UTF-8 must remain intact');
$long=implode(' ',array_fill(0,40,'hello'));$bounded=phpbb_topic_highlight_pattern($long);th_check(strlen($bounded)<4096,'Duplicate terms must not expand the regular expression');
$backtrack=ini_get('pcre.backtrack_limit');$jit=ini_get('pcre.jit');ini_set('pcre.jit','0');ini_set('pcre.backtrack_limit','1');
$body=str_repeat('a',10000).'c';$result=phpbb_topic_highlight_apply($body,phpbb_topic_highlight_pattern('a*a*a*a*b'),'ff0000');
th_check($result===$body&&preg_last_error()!==PREG_NO_ERROR,'Actual PCRE resource failure must retain the complete original post');
ini_set('pcre.backtrack_limit',$backtrack);if($jit!==false){ini_set('pcre.jit',$jit);}
restore_error_handler();echo 'Topic highlighting ',$argv[1],': ',$cases,' actual controller cases, ',$checks," assertions PASS; UTF-8, wildcards, HTML, input shapes, navigation and PCRE failure.\n";
