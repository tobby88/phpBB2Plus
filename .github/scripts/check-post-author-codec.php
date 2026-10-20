<?php
define('IN_PHPBB',true);$root=dirname(dirname(__DIR__)).'/phpBB2/';require$root.'includes/functions_username.php';$cases=0;
function pac_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$tpl=file_get_contents($root.'templates/fisubsilversh/viewtopic_body.tpl');pac_check(strpos($tpl,'<strong>{postrow.POSTER_NAME}</strong>')!==false,'Actual standard byline slot');
foreach(array('', '0','Größe 😀',"O'Reilly \\ path",'Literal &amp; &#91; &quot;',str_repeat('😀',255),str_repeat('&',255),str_repeat("'",255),str_repeat(chr(34),255),'</strong><img src=https://byline.example.invalid/inert.png>',str_repeat('A',255))as$raw){
 $key=phpbb_post_author_key($raw);pac_check($key!==null&&phpbb_post_author_stored($key)===$key&&html_entity_decode($key,ENT_QUOTES,'UTF-8')===$raw,'Exact guest raw/prepared/storage/display roundtrip without second decode');
 $html=str_replace('{postrow.POSTER_NAME}',$key,'<strong>{postrow.POSTER_NAME}</strong>');pac_check(substr_count($html,'<strong>')===1&&substr_count($html,'</strong>')===1&&strpos($html,'<img')===false&&html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8')===$raw,'Actual standard byline slot stays literal HTML text');$cases++;
}
pac_check(strlen(phpbb_post_author_key(str_repeat("'",255)))===1530,'Worst-case supported entity expansion fits declared byline capacity');$cases++;
foreach(array(array('bad'),false,1,"bad\0name","bad\xff",str_repeat('😀',256),str_repeat('x',1021))as$bad){pac_check(phpbb_post_author_input($bad)===null&&phpbb_post_author_key($bad)===null,'Invalid/oversized guest raw input is not cast or clipped');$cases++;}
foreach(array('<b>literal</b>','raw & name',"raw'quote",str_repeat('x',1531),"bad\0name")as$bad){pac_check(phpbb_post_author_stored($bad)===null,'Noncanonical/unprepared/oversized stored byline is rejected');$cases++;}
foreach(array(str_repeat('ä',26),str_repeat('😀',26),str_repeat('&',26))as$wide){pac_check(phpbb_username_input($wide)===null&&phpbb_username_key($wide)==='','Account-name limits and escaping policy remain unchanged');$cases++;}
pac_check(phpbb_post_author_search_allowed('ü*',1)&&!phpbb_post_author_search_allowed('ü*',2)&&phpbb_post_author_search_allowed('0',3)&&phpbb_post_author_search_allowed(str_repeat('ü',255),2)&&!phpbb_post_author_search_allowed(str_repeat('ü',256),2),'Guest search keeps Unicode wildcard minima and complete bounded literal names');$cases++;
echo 'Post author codec: '.$cases." actual guest encoding/template/storage/limits/account-separation/search checks passed; no network.\n";
