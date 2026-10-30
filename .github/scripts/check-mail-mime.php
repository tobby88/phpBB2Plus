<?php
require dirname(dirname(__DIR__)).'/phpBB2/includes/functions_mail_headers.php';
$mime_cases=0;
function mh_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['mime_cases']++;}
function mh_decode($header,$prefix){
 if(strpos($header,'=?UTF-8?B?')!==0){mh_check(strlen($prefix.$header)<=76,'Plain header line bound');return$header;}
 $words=preg_split('/\r\n /',$header);$out='';
 foreach($words as$i=>$word){
  mh_check(strlen(($i===0?$prefix:' ').$word)<=76&&strlen($word)<=75,'Wire header/encoded-word length limits');
  mh_check(preg_match('/^=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=$/D',$word,$m)===1,'Complete encoded-word grammar');
  $decoded=base64_decode($m[1],true);mh_check($decoded!==false&&preg_match('//u',$decoded)===1,'Each encoded word independently decodes as UTF-8');$out.=$decoded;
 }
 return$out;
}
foreach(array('', 'ASCII subject', '=?UTF-8?B?R3LDvMOfZQ==?=',"Grüße O'Reilly 😀", "A\tB",str_repeat('plain text ',25),str_repeat('😀',200))as$text){
 $header=phpbb_mail_header_value($text);mh_check(mh_decode($header,'Subject: ')===$text,'Header exact logical round trip');mh_check(mh_decode($header,'X-AntiAbuse: ')===$text,'Diagnostic header exact logical round trip');
}
foreach(array('ä','€','😀',"é")as$char){for($boundary=29;$boundary<45;$boundary++){
 $text=str_repeat('x',$boundary).str_repeat($char,50).' end';mh_check(mh_decode(phpbb_mail_header_value($text),'Subject: ')===$text,'Every adjacent UTF-8 byte/chunk boundary');
}}
foreach(array("\xff", "bad\x01text",array('subject'),123)as$invalid){$denied=false;try{phpbb_mail_header_value($invalid);}catch(RuntimeException$e){$denied=true;}mh_check($denied,'Invalid header text rejected');}
mh_check(phpbb_mail_header_value("Line\r\nBcc: injected\0")==='LineBcc: injected','User line breaks cannot create another field');
$wire=phpbb_mail_transport_headers("From: forum@example.invalid\nX-AntiAbuse: Username - Grüße 😀\nBcc: hidden@example.invalid\n");
$lines=explode("\r\n",$wire);mh_check($lines[0]==='From: forum@example.invalid'&&strpos($wire,'Bcc: hidden@example.invalid')!==false,'Structured addresses stay byte-exact');
$diag=substr($wire,strpos($wire,'X-AntiAbuse: ')+strlen('X-AntiAbuse: '));$diag=substr($diag,0,strpos($diag,"\r\nBcc:"));mh_check(mh_decode($diag,'X-AntiAbuse: ')==='Username - Grüße 😀','Diagnostic field uses independent Unicode encoding');
foreach(array("Grüße 😀\n.\nEnd",str_repeat("à = 😀 ",200)."\r\nTrailing \t", "First\rSecond\nThird\r\nLast")as$text){
 $encoded=phpbb_mail_transport_body($text);$expected=str_replace(array("\r\n","\r","\n"),array("\n","\n","\r\n"),$text);
 mh_check(quoted_printable_decode($encoded)===$expected,'Body survives exact Unicode/equals/whitespace/line-ending round trip');
 mh_check(!preg_match('/[^\x09\x0a\x0d\x20-\x7e]/',$encoded),'Wire body is seven-bit safe');
 foreach(explode("\r\n",$encoded)as$line){mh_check(strlen($line)<=76,'Quoted-printable line bound');}
}
echo 'Mail MIME: ',$mime_cases," Unicode boundary/grammar/line-limit/diagnostic/body cases PASS; no transport.\n";
