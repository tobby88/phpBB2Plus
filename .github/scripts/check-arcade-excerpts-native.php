<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_EXCERPT_NATIVE')!=='1'){echo "Arcade excerpt checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/arcade-excerpt-fixture.php';require $as_app.'db/mysqli.php';
$db=$control=null;$created=false;$count=0;$schema='codex_arc_excerpt_'.bin2hex(phpbb_random_bytes(8));
try{
 $port=getenv('PHPBB_ARCADE_EXCERPT_PORT')?:'3306';as_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback fixture port');$host='127.0.0.1:'.$port;$password=getenv('PHPBB_ARCADE_EXCERPT_PASSWORD')?:'';
 $control=new sql_db($host,'root',$password,'',false);as_check((bool)$control->db_connect_id,'Own native server');as_check(preg_match('/^codex_arc_excerpt_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive native schema');$created=true;
 $db=new sql_db($host,'root',$password,$schema,false);as_check((bool)$db->db_connect_id,'Own native schema');$canonical=file_get_contents($as_app.'install/schemas/mysql_schema.sql');as_check(preg_match('/CREATE TABLE phpbb_ina_games \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1&&$db->sql_query(str_replace('phpbb_ina_games','fixture_ina_games',$m[0])),'Canonical Arcade games table');
 foreach(array(null,'0','Grüße 😀',str_repeat('a',22).'😀Z',str_repeat('😀',25),str_repeat('😀',26),str_repeat('x',23).'&amp;',"A\\B &#39; '",str_repeat('x',23).'&<script>x</script>')as$raw){
  as_check($db->sql_query('DELETE FROM fixture_ina_games')&&$db->sql_query("INSERT INTO fixture_ina_games(game_id,game_name,game_desc) VALUES(9,'fixture_game',".($raw===null?'NULL':"'".$db->sql_escape($raw)."'").')'),'Own canonical row');
  $r=$db->sql_query('SELECT * FROM fixture_ina_games');as_check((bool)$r,'Actual stored game read');$before=$db->sql_fetchrow($r);$db->sql_freeresult($r);as_check($before['game_desc']===$raw,'Exact canonical raw game caption');$parts=preg_split('//u',(string)$raw,-1,PREG_SPLIT_NO_EMPTY);$expected=count($parts)>25?implode('',array_slice($parts,0,25)).'...':(string)$raw;
  for($site=0;$site<3;$site++){ae_check(ae_render($site,$before['game_desc']),$expected,$site);$count++;}
  $r=$db->sql_query('SELECT * FROM fixture_ina_games');as_check((bool)$r,'Canonical row after rendering');$after=$db->sql_fetchrow($r);$db->sql_freeresult($r);as_check($before===$after,'Display never changes stored caption or game metadata');
 }
 echo 'Native Arcade excerpts: '.$count." canonical stored-caption/link cases PASS; exact raw data, stable targets and read-only rendering.\n";
}finally{if($db){$db->sql_close();}if($created){as_check(preg_match('/^codex_arc_excerpt_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Exact own schema cleanup');}if($control){$control->sql_close();}}
