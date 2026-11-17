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
