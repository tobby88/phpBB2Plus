<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_SHOUT_TEXT_NATIVE')!=='1'){echo "Shoutbox text checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/shoutbox-text-fixture.php';require $sx_app.'db/mysqli.php';
class SxDatabase extends sql_db
{
	var $writes=array();
	function sql_query($sql='',$transaction=false){if(preg_match('/^(?:INSERT|UPDATE|DELETE)\b/i',ltrim($sql))){$this->writes[]=$sql;}return parent::sql_query($sql,$transaction);}
}
function sn_sql($sql){$r=$GLOBALS['db']->sql_query($sql);sx_check($r!==false,'Own canonical fixture SQL');return$r;}
function sn_rows(){ $r=sn_sql('SELECT * FROM fixture_shout ORDER BY shout_id');$rows=array();while($row=$GLOBALS['db']->sql_fetchrow($r)){$rows[]=$row;}$GLOBALS['db']->sql_freeresult($r);return$rows; }
function sn_seed(){sn_sql('DELETE FROM fixture_shout');sn_sql("INSERT INTO fixture_shout(shout_id,shout_text,shout_user_id,shout_session_time) VALUES(1,'old fixture marker',99,1),(2,'recent fixture marker',98,".time().")");$GLOBALS['db']->writes=array();}
$port=getenv('PHPBB_SHOUT_TEXT_PORT')?:'3306';$password=getenv('PHPBB_SHOUT_TEXT_PASSWORD')?:'';sx_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$host='127.0.0.1:'.$port;
$control=new sql_db($host,'root',$password,'',false);sx_check($control->db_connect_id,'Owned loopback fixture server');$fixture='codex_shout_text_'.bin2hex(phpbb_random_bytes(8));$created=false;$db=null;
try{
 sx_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive own schema');$created=true;$db=new SxDatabase($host,'root',$password,$fixture,false);sn_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");$schema=file_get_contents($sx_app.'install/schemas/mysql_schema.sql');
 foreach(array('shout','words','smilies')as$table){sx_check(preg_match('/CREATE TABLE phpbb_'.$table.' \(.*?\) ENGINE=InnoDB[^;]*;/s',$schema,$ddl)===1,'Canonical InnoDB/utf8mb4 schema');sn_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$ddl[0]));}
 sn_sql("INSERT INTO fixture_words(word,replacement) VALUES('badword','censored')");sn_sql("INSERT INTO fixture_smilies(code,smile_url,emoticon) VALUES(':)','icon_smile.gif','Synthetic smile')");
 foreach(array('english','german')as$locale){sx_language($locale);
  foreach(array('shoutbox.php','shoutbox_max.php')as$route){
   foreach(array(array(0,0,0),array(1,0,0),array(0,1,0),array(1,1,1))as$flags){foreach(array('0',"Grüße 😀 O'Reilly \"quoted\" C:\\temp\nnext",'<b>HTML</b> [b]BBCode[/b] badword :)','Literal &lt;b&gt; &#039;')as$raw){
    sn_seed();$before=sn_rows();$r=sx_run($route,$raw,'post',$flags);$rows=sn_rows();sx_check(!$r['error']&&!$r['rejected']&&count($rows)===3,'Actual authorized shout is stored, including zero');$row=$rows[2];sx_check($rows[0]===$before[0]&&$rows[1]===$before[1],'Ordinary submission preserves prior rows');sx_check($row['shout_username']===$r['username']&&$row['shout_user_id']==='4','Session identity is stored exactly; POST name is ignored');
    $expected=phpbb_shoutbox_prepare_message($r['text'],$flags[0],$flags[1],$flags[2],$row['shout_bbcode_uid']);sx_check($expected!==false&&$row['shout_text']===stripslashes($expected)&&preg_match('//u',$row['shout_text'])===1,'Exact canonical prepared UTF-8 TEXT round trip');sx_check((int)$row['enable_bbcode']===$flags[1]&&(int)$row['enable_html']===$flags[0]&&(int)$row['enable_smilies']===$flags[2],'Formatting switches remain bound to the stored message');
    $rendered=sx_render_stored($row['shout_text'],$row['shout_bbcode_uid'],$flags);if($raw==='0'){sx_check($rendered==='0','Stored zero is visible');}if(strpos($raw,'Grüße')===0){sx_check(html_entity_decode(strip_tags(str_replace('<br />',"\n",$rendered)),ENT_QUOTES,'UTF-8')===$raw,'Stored/displayed Unicode, both quote types, backslashes and newlines equal the original independent of helper output');}if($route==='shoutbox_max.php'){$p=sx_run($route,$raw,'preview',$flags);$prepared=phpbb_shoutbox_prepare_message($p['text'],$flags[0],$flags[1],$flags[2],$p['uid']);sx_check($p['vars']['MESSAGE']===sx_render_stored(stripslashes($prepared),$p['uid'],$flags),'Actual preview matches the same stored display semantics');sx_check(count(sn_rows())===3,'Preview never inserts or prunes a shout');}$sx_cases++;
   }}
   foreach(array(str_repeat('a',15999).'ü',str_repeat('a',15998).'😀',str_repeat('😀',5000),str_repeat('&',16000),str_repeat('"',16000),"bad\xff",array('bad'),'','   ')as$raw){
    sn_seed();$before=sn_rows();$r=sx_run($route,$raw);$rows=sn_rows();if($r['error']||$r['text']===''){sx_check($rows===$before&&!$db->writes,'Invalid/empty/expanded-overflow text writes and prunes nothing');if($r['error']){$key=is_array($raw)||$raw==="bad\xff"?'Shoutbox_invalid_text':'Shoutbox_format_failed';sx_check($r['error_msg']===$lang[$key],'Localized failure distinguishes invalid text and formatting overflow');}}
    else{sx_check(count($rows)===3&&preg_match('//u',$rows[2]['shout_text'])===1&&strlen($rows[2]['shout_text'])<=65535,'A byte-boundary message stores complete UTF-8 without SQL1366/1406');sx_check(html_entity_decode($rows[2]['shout_text'],ENT_QUOTES,'UTF-8')===$r['text'],'Stored accepted prefix is not corrupted');}$sx_cases++;
   }
   foreach(array(array('sid'=>'wrong'),array('sid'=>array('bad')),array('method'=>'GET'),array('no_auth'=>true),array('guest'=>true))as$options){sn_seed();$before=sn_rows();sx_run($route,'Blocked input','post',array(0,0,0),$options);sx_check(sn_rows()===$before&&!$db->writes,'Method/session/guest/current permission failures write nothing');$sx_cases++;}
   sn_seed();sn_sql('UPDATE fixture_shout SET shout_user_id=4,shout_session_time='.time().' WHERE shout_id=2');$before=sn_rows();$db->writes=array();$r=sx_run($route,'Flood blocked','post',array(0,0,0),array('flood'=>3600));sx_check($r['error']&&$r['error_msg']===$lang['Flood_Error']&&sn_rows()===$before&&!$db->writes,'Flood rejection retains existing data');$sx_cases++;
   foreach(array('new valid shout',str_repeat('&',16000))as$raw){sn_seed();$before=sn_rows();$r=sx_run($route,$raw,'post',array(0,0,0),array('prune'=>1));$rows=sn_rows();sx_check($r['error']?($rows===$before&&!$db->writes):(count($rows)===2&&$rows[0]===$before[1]),'Auto-prune runs only after successful insertion');$sx_cases++;}
  }
 }
 echo 'Shoutbox text native ',PHPBB_LEGACY_REQUEST_ESCAPED?'legacy':'raw',': ',$sx_cases,' cases, ',$sx_checks," assertions PASS; canonical UTF-8/TEXT storage, both real controllers, preview parity, names, zero, limits, session/flood guards and scoped pruning.\n";
}finally{if($db){$db->sql_close();}if($created){sx_check(preg_match('/^codex_shout_text_[a-f0-9]{16}$/D',$fixture)&&$control->sql_query('DROP DATABASE '.$fixture),'Exact own schema cleanup');}$control->sql_close();restore_error_handler();}
