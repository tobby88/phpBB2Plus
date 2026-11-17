<?php
// The real stored-text encoder/parser/formatter, with synthetic content only.
function nt_export_format_suite()
{
    global $board_config,$userdata,$lang,$nt_checks,$template;
    require_once $GLOBALS['nt_app'].'includes/functions_topic_export.php';
    $cases=0;$uid='abc1234567';$before=$nt_checks;
    foreach(array('english','german')as$locale){
        nt_context($locale,true);$template=nt_template('news.tpl');
        foreach(array(
            "A  B\tC\n\nD",
            "Ä Ö Ü ß 😀 & <literal> &amp;",
            "[code]\tif (a  < b) {\n    return '&amp;';\n}\n[/code]",
            "[php]\t\$a = '<tag> &amp;';\n    echo \$a;[/php]"
        )as$raw){
            $stored=bbencode_first_pass(htmlspecialchars($raw,ENT_QUOTES,'UTF-8'),$uid);
            $actual=phpbb_topic_export_body($stored,$uid);
            $expected=preg_replace('~\[/?(?:code|php)\]~i','',$raw);
            $expected=str_replace("\n","\r\n",$expected);
            nt_check($actual===$expected,'Actual first/second pass export preserves exact spaces, tabs, Unicode, code and one entity layer: '.json_encode(array($actual,$expected)));$cases++;
        }
        foreach(array(
            array('[b]Größe[/b] &amp;', 'Größe &amp;'),
            array('[url=https://example.invalid/?a=1&b=2]Änderung[/url]', 'Änderung (https://example.invalid/?a=1&b=2)'),
            array('[url]https://example.invalid/[/url]', 'https://example.invalid/'),
            array('[img]https://example.invalid/a.png[/img]', 'https://example.invalid/a.png')
        )as$v){$stored=bbencode_first_pass(htmlspecialchars($v[0],ENT_QUOTES,'UTF-8'),$uid);$actual=phpbb_topic_export_body($stored,$uid);nt_check($actual===$v[1],'Actual BBCode exports readable text and link/image destinations: '.json_encode(array($stored,$actual,$v[1])));$cases++;}
        foreach(array('"',"'",'')as$quote){$href='https://example.invalid/?a=1&amp;b=2';$html='<a href='.$quote.$href.$quote.'>Label</a><br><img src='.$quote.$href.$quote.'>';nt_check(phpbb_topic_export_text($html)==="Label (https://example.invalid/?a=1&b=2)\nhttps://example.invalid/?a=1&b=2",'Quoted/unquoted attributes and HTML line breaks');$cases++;}
        $board_config['allow_bbcode']=0;
        nt_check(phpbb_topic_export_body('[b:'.$uid.']A  &amp; B[/b:'.$uid.']',$uid)==='[b]A  & B[/b]','Disabled BBCode remains readable source without storage stamps');$cases++;
        $row=nt_rows()[0];$row['post_text']="A  B\tC";$snapshot=serialize($row);$row['user_from']='LOCATION_MUST_NOT_LEAK';$row['user_email']='EMAIL_MUST_NOT_LEAK';
        $out=phpbb_topic_export_row($row);nt_check(strpos($out,"Current & member:\r\nA  B\tC")!==false&&strpos($out,'MUST_NOT_LEAK')===false,'Only byline and text exported, no unused profile fields');$cases++;
        $row['post_deleted']=1;nt_check(phpbb_topic_export_row($row)==="\r\n".$lang['Post_deleted_placeholder']."\r\n",'Deleted row has exactly one localized marker, no recoverable body');$cases++;
        $row['post_deleted']=0;$row['user_id']=null;$row['poster_id']=99;nt_check(strpos(phpbb_topic_export_row($row),$lang['Former_member'].':')!==false,'Missing account has generic localized byline');$cases++;
    }
    foreach(array('Ä &amp; 😀','../../x'."\r\n".'Content-Type: evil',str_repeat('😀',70),'CON','nul.txt','...','bad'."\xff")as$title){$header=phpbb_topic_export_filename($title,12);nt_check(preg_match('/^attachment; filename="[a-zA-Z0-9._-]+\.txt"; filename\*=UTF-8\x27\x27[^\r\n]*$/D',$header)===1,'Safe ASCII fallback and extended UTF-8 filename');$unicode=rawurldecode(substr($header,strpos($header,"UTF-8''")+7));nt_check(preg_match('//u',$unicode)===1&&strlen($unicode)<=184&&strpos($unicode,'/')===false&&strpos($unicode,'\\')===false,'Whole Unicode characters, bounded filename and no path separators: '.bin2hex($unicode));$cases++;}
    $stream=fopen('php://temp','w+b');$bytes=0;try{phpbb_topic_export_write($stream,'Ä',$bytes,2);nt_check($bytes===2&&ftell($stream)===2,'Length measures bytes, not characters');$failed=false;try{phpbb_topic_export_write($stream,'X',$bytes,2);}catch(PhpbbTopicExportFailure$e){$failed=$e->getMessage()==='Topic_export_too_large';}nt_check($failed&&$bytes===2&&ftell($stream)===2,'Size failure writes no partial extra piece');$cases++;}finally{fclose($stream);}
    $failed=false;try{phpbb_topic_export_write($stream,'X',$bytes,100);}catch(PhpbbTopicExportFailure$e){$failed=true;}nt_check($failed,'Closed stream cannot falsely acknowledge a download');$cases++;
    echo 'Topic export format: ',$cases,' cases, ',$nt_checks-$before," assertions PASS; actual parser, whitespace, entities, Unicode, code, links, compact deletions and bounded filenames/stream.\n";
}

function nt_export_markup_suite()
{
    global $template,$board_config,$userdata,$lang,$nt_checks;
    $cases=0;$before=$nt_checks;$uid='abc1234567';
    foreach(array('english','german')as$locale){
        nt_context($locale,true);$template=nt_template('news.tpl');
        foreach(array(
            array('[url=https://example.invalid/target][img:'.$uid.']https://example.invalid/source.png[/img:'.$uid.'][/url]','https://example.invalid/source.png (https://example.invalid/target)'),
            array('[url=https://example.invalid/same.png][img:'.$uid.']https://example.invalid/same.png[/img:'.$uid.'][/url]','https://example.invalid/same.png'),
            array('[list:'.$uid.'][*:'.$uid.']One[*:'.$uid.']Two[/list:u:'.$uid.']',"- One\r\n- Two\r\n"),
            array('[list=1:'.$uid.'][*:'.$uid.']One[*:'.$uid.']Two[/list:o:'.$uid.']',"1. One\r\n2. Two\r\n"),
            array('[list=a:'.$uid.'][*:'.$uid.']Ä[*:'.$uid.']😀[/list:o:'.$uid.']',"a. Ä\r\nb. 😀\r\n"),
            array('[list:'.$uid.'][*:'.$uid.']Outer[list=1:'.$uid.'][*:'.$uid.']Inner[/list:o:'.$uid.'][*:'.$uid.']Last[/list:u:'.$uid.']',"- Outer\r\n  1. Inner\r\n- Last\r\n"),
            array('[code:'.$uid.']<span data-sxbb-controls="1">literal [list] &amp;amp;</span>[/code:'.$uid.']','<span data-sxbb-controls="1">literal [list] &amp;</span>')
        )as$v){nt_check(phpbb_topic_export_body($v[0],$uid)===$v[1],'Actual BBCode markup export keeps image destinations, distinct list items/numbering/nesting and literal code: '.json_encode($v));$cases++;}
        $quote='[quote:'.$uid.'="Synthetic Member"]Quote ä  text[/quote:'.$uid.']';
        foreach(array($quote,'[quote:'.$uid.']Outer '.$quote.' tail[/quote:'.$uid.']')as$source){$out=phpbb_topic_export_body($source,$uid);nt_check(strpos($out,'Synthetic Member')!==false&&strpos($out,'Quote ä  text')!==false,'Actual quote byline and full text remain');foreach(array('Select','Contract','markieren','einklappen')as$label){nt_check(strpos($out,$label)===false,'Actual hidden quote toolbar labels stay out of plain text');}$cases++;}
        foreach(array('"',"'","")as$q){
            $html='Before<span data-sxbb-controls='.$q.'1'.$q.'><span><button>'.$lang['Select'].'</button></span><b>'.$lang['Contract'].'</b></span>After';
            nt_check(phpbb_topic_export_text($html)==='BeforeAfter','Nested translated control content is removed by markup, not localized text');$cases++;
        }
    }
    foreach(array(
        array('<a href="https://example.invalid/real" data-href="https://example.invalid/wrong"><b>Ä</b><img src="https://example.invalid/image" data-src="https://example.invalid/wrong-image"></a>','Ähttps://example.invalid/image (https://example.invalid/real)'),
        array('<a href="https://example.invalid/?a=1&gt;0&amp;b=2">Label</a>','Label (https://example.invalid/?a=1>0&b=2)'),
        array('<a href="https://example.invalid/"></a>','https://example.invalid/'),
        array('<a href="mailto:x@example.invalid">x@example.invalid</a>','x@example.invalid'),
        array('A<!-- a > comment -->B<br>C', "AB\nC"),
        array('&lt;li&gt;literal&lt;/li&gt; &amp;amp;', '<li>literal</li> &amp;'),
        array('<ul><li>One</li><li>Two</li></ul>',"- One\n- Two\n"),
        array('<ol type="A"><li>One<li>Two</ol>',"A. One\nB. Two\n"),
        array('<img alt="Ä &amp; 😀">','Ä & 😀'),
        array('<a href="https://example.invalid/?a=>">Label</a>','Label (https://example.invalid/?a=>)')
    )as$v){nt_check(phpbb_topic_export_text($v[0])===$v[1],'Text conversion respects complete attributes, quoted greater-than, entities, comments and optional li closers: '.json_encode($v));$cases++;}
    $items='';for($i=1;$i<=28;$i++){$items.='<li>Item'.$i;}$out=phpbb_topic_export_text('<ol type="a">'.$items.'</ol>');nt_check(strpos($out,"z. Item26\naa. Item27\nab. Item28\n")!==false,'Alphabetic numbering continues after z');$cases++;
    foreach(array('<span data-sxbb-controls="1">unfinished','<a href="https://example.invalid/">unfinished',str_repeat('<ul>',257).'X'.str_repeat('</ul>',257),str_repeat('<a href="x">',257).'X'.str_repeat('</a>',257))as$html){$failed=false;try{phpbb_topic_export_text($html);}catch(PhpbbTopicExportFailure$e){$failed=true;}nt_check($failed,'Incomplete or excessive markup fails without partial text');$cases++;}
    $limit=ini_get('pcre.backtrack_limit');try{ini_set('pcre.backtrack_limit','1');$failed=false;try{phpbb_topic_export_text('<a href="https://example.invalid/">Label</a>');}catch(PhpbbTopicExportFailure$e){$failed=true;}nt_check($failed,'PCRE failure must not erase content and masquerade as success');$cases++;}finally{ini_set('pcre.backtrack_limit',$limit);}
    echo 'Topic export markup: ',$cases,' cases, ',$nt_checks-$before," assertions PASS; actual quotes and hidden controls, linked images, numbered/nested lists, literal code, exact attributes and honest parser failures.\n";
}
