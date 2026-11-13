<?php
if (PHP_SAPI !== 'cli' || !defined('IN_PHPBB')) { return; }

function bn_prepare($locale = 'english')
{
 tp_prepare(null,0,$locale);tn_sql('UPDATE fixture_topics SET topic_poster=7 WHERE topic_id=100');tn_sql('DELETE FROM fixture_words');
 $GLOBALS['board_config']['max_link_bookmarks']=5;$GLOBALS['board_config']['topics_per_page']=2;
}
function bn_add($id,$forum=3,$stamp=3)
{
 $post=200+$id;
 tn_sql("INSERT INTO fixture_topics(topic_id,forum_id,topic_title,topic_poster,topic_first_post_id,topic_last_post_id) VALUES($id,$forum,'Synthetic topic $id',7,$post,$post)");
 tn_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip) VALUES($post,$id,$forum,7,$stamp,'7f000001')");
 tn_sql("INSERT INTO fixture_bookmarks(topic_id,user_id) VALUES($id,7)");
}
function bn_ids($rows){return array_map(function($row){return(int)$row['topic_id'];},$rows);}
function bn_rows($limit=5){return phpbb_bookmark_navigation_rows($GLOBALS['db'],$GLOBALS['userdata'],$limit);}
function bn_header($code)
{
 global$db,$userdata,$lang,$board_config,$phpEx,$nav_links;
 eval($code);return isset($nav_links['bookmark'])?$nav_links['bookmark']:array();
}
function bn_search($code,$ids,$start=0)
{
 global$db,$userdata,$lang,$board_config;
 $search_results=implode(',',$ids);$show_results='bookmarks';$sort_by=0;$sort_dir='DESC';$is_ajax=false;
 eval($code);return array('ids'=>bn_ids($searchset),'total'=>$total_match_count,'start'=>$start);
}
function tp_bookmark_navigation()
{
 global$tn_app,$userdata,$board_config,$nav_links,$tp_hook,$tp_factory,$tp_candidates,$tp_busy,$db,$tree;
 require_once$tn_app.'includes/functions_bookmark_navigation.php';require_once$tn_app.'includes/functions_search.php';
 $source=file_get_contents($tn_app.'includes/functions.php');$a=strpos($source,"\t// Add bookmarks to Navigation bar");$b=strpos($source,"\t// Set up style",$a);nt_check($a!==false&&$b>$a,'Actual complete header bookmark path');
 $header='';foreach(token_get_all('<?php '.substr($source,$a,$b-$a))as$t){if(is_array($t)&&$t[0]===T_OPEN_TAG){continue;}$header.=is_array($t)?($t[0]===T_FILE?var_export($tn_app.'includes/functions.php',true):$t[1]):$t;}
 $source=file_get_contents($tn_app.'search.php');$a=strpos($source,'$this_auth = auth(AUTH_ALL,AUTH_LIST_ALL,$userdata);');$b=strpos($source,'$orig_word = array();',$a);nt_check($a!==false&&$b>$a,'Actual count/query/fetch bookmark search path');$search=substr($source,$a,$b-$a);
 $cases=$faults=0;
 foreach(array('english','german')as$locale){
  bn_prepare($locale);$before=tp_snapshot();$rows=bn_rows();$nav_links=array();$links=bn_header($header);
  nt_check(bn_ids($rows)===array(100)&&count($links)===1&&$links[0]['title']==='Current &amp; Grüße 😀 title'&&tp_snapshot()===$before&&tp_released(),'Ordinary actual Unicode/entity title and released read-only owner');$cases++;
  bn_prepare($locale);tn_sql('INSERT INTO fixture_bookmarks(topic_id,user_id) VALUES(100,7),(100,7)');$board_config['max_link_bookmarks']=1;$nav_links=array();$before=tp_snapshot();$links=bn_header($header);
  nt_check(count($links)===1&&bn_ids(bn_rows(1))===array(100)&&tp_snapshot()===$before&&tp_released(),'Duplicate rows consume one header slot and cannot fabricate a more link');$cases++;
  bn_prepare($locale);foreach(array(101,102,103)as$id){bn_add($id);}$board_config['max_link_bookmarks']=2;$nav_links=array();$before=tp_snapshot();$links=bn_header($header);$first=bn_search($search,array(100,101,102,103));$second=bn_search($search,array(100,101,102,103),2);
  nt_check(bn_ids(bn_rows(2))===array(103,102,101)&&count($links)===3&&strpos($links[0]['url'],'t=103')!==false&&strpos($links[1]['url'],'t=102')!==false&&$links[2]['url']==='search.php?search_id=bookmarks&start=2','Distinct stable header limit and canonical more link');
  nt_check($first['ids']===array(103,102)&&$second['ids']===array(101,100)&&$first['total']===4&&$second['total']===4&&tp_snapshot()===$before&&tp_released(),'Actual search pages use the same unique tie order without overlap or gaps');$cases++;
  foreach(array('view','read','both')as$denied){
   bn_prepare($locale);bn_add(101,4);tn_sql('UPDATE fixture_forums SET auth_view='.($denied==='read'?0:5).',auth_read='.($denied==='view'?0:5).' WHERE forum_id=3');$before=tp_snapshot();$rows=bn_rows();$results=bn_search($search,array(100,101));
   nt_check(bn_ids($rows)===array(101)&&$results['ids']===array(101)&&$results['total']===1&&tp_snapshot()===$before&&tp_released(),'Both view and read required in header and search: '.$denied);$cases++;
  }
  foreach(array('orphan-topic','orphan-forum','orphan-first','orphan-last','orphan-author','orphan-last-author','first-wrong-topic','last-wrong-forum','all-deleted','moved','status','deleted-first','locked')as$kind){
   bn_prepare($locale);bn_add(101);$changes=array('orphan-topic'=>'DELETE FROM fixture_topics WHERE topic_id=101','orphan-forum'=>'UPDATE fixture_topics SET forum_id=88 WHERE topic_id=101','orphan-first'=>'UPDATE fixture_topics SET topic_first_post_id=9999 WHERE topic_id=101','orphan-last'=>'UPDATE fixture_topics SET topic_last_post_id=9999 WHERE topic_id=101','orphan-author'=>'UPDATE fixture_topics SET topic_poster=9999 WHERE topic_id=101','orphan-last-author'=>'UPDATE fixture_posts SET poster_id=9999 WHERE post_id=301','first-wrong-topic'=>'UPDATE fixture_topics SET topic_first_post_id=10 WHERE topic_id=101','last-wrong-forum'=>'UPDATE fixture_posts SET forum_id=4 WHERE post_id=301','all-deleted'=>'UPDATE fixture_posts SET post_deleted=1 WHERE topic_id=101','moved'=>'UPDATE fixture_topics SET topic_moved_id=100 WHERE topic_id=101','status'=>'UPDATE fixture_topics SET topic_status=99 WHERE topic_id=101','deleted-first'=>'UPDATE fixture_posts SET post_deleted=1 WHERE post_id=10','locked'=>'UPDATE fixture_topics SET topic_status=1 WHERE topic_id=101');tn_sql($changes[$kind]);$expected=in_array($kind,array('deleted-first','locked'),true)?array(101,100):array(100);$before=tp_snapshot();
   nt_check(bn_ids(bn_rows())===$expected&&bn_search($search,array(100,101))['ids']===$expected&&tp_snapshot()===$before&&tp_released(),'Header/search canonical live parents, compact deleted first posts and locked topics: '.$kind);$cases++;
  }
  bn_prepare($locale);$nav_links=array('bookmark'=>array(array('title'=>'Stale','url'=>'viewtopic.php?t=999')));$board_config['max_link_bookmarks']=0;nt_check(bn_header($header)===array()&&tp_released(),'Disabled navigation clears prior links without opening an owner');$cases++;
  bn_prepare($locale);$board_config['topics_per_page']=0;$board_config['max_link_bookmarks']=1;bn_add(101);$nav_links=array();$links=bn_header($header);nt_check(count($links)===2&&$links[1]['url']==='search.php?search_id=bookmarks&start=1'&&tp_released(),'Malformed zero page size cannot divide by zero');$cases++;
 }
 foreach(array('missing','foreign','logout','case','expired','block','inactive','deleted','ban')as$change){
  bn_prepare();mysqli_query($db->db_connect_id,'START TRANSACTION');$r=$db->sql_query('SELECT * FROM fixture_sessions');$db->sql_freeresult($r);nt_check(mysqli_query($db->db_connect_id,"INSERT INTO fixture_config(config_name,config_value) VALUES('nav-pending','private')"),'Own pending caller marker');
  try{tp_change($change);$before=tp_snapshot();nt_check(bn_rows()===array()&&tp_snapshot()===$before&&tp_released(),'Fresh header authority ignores caller snapshot: '.$change);$r=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='nav-pending'");$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);nt_check($row['config_value']==='private'&&!tp_rows("SELECT * FROM fixture_config WHERE config_name='nav-pending'")&&$db->db_connect_id,'Caller remains open, private and uncommitted');}finally{mysqli_query($db->db_connect_id,'ROLLBACK');}$cases++;
 }
 foreach(array('grant','pending','revoked','stale-admin','cached-tree')as$kind){
  bn_prepare();tn_sql('UPDATE fixture_forums SET auth_view=2,auth_read=2 WHERE forum_id=3');tn_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(50,7,'.($kind==='pending'?1:0).')');tn_sql('INSERT INTO fixture_auth_access(group_id,forum_id,auth_view,auth_read) VALUES(50,3,1,'.($kind==='revoked'?0:1).')');if($kind==='stale-admin'){$userdata['user_level']=ADMIN;tn_sql('DELETE FROM fixture_auth_access');}if($kind==='cached-tree'){tn_sql('DELETE FROM fixture_auth_access');$tree=array('data'=>array(array('forum_id'=>3,'auth_view'=>0,'auth_read'=>0)),'keys'=>array('f3'=>0),'type'=>array(POST_FORUM_URL));}$before=tp_snapshot();
  nt_check(bn_ids(bn_rows())===($kind==='grant'?array(100):array())&&tp_snapshot()===$before&&tp_released(),'Actual current auth ignores stale roles/tree and pending/revoked grants: '.$kind);$tree=array();$cases++;
 }
 foreach(array('guest','logged-array','user-array','user-range','sid-empty','sid-array','sid-long','sid-nul')as$bad){
  bn_prepare();if($bad==='guest'){$userdata['session_logged_in']=false;}elseif($bad==='logged-array'){$userdata['session_logged_in']=array(1);}elseif($bad==='user-array'){$userdata['user_id']=array(7);}elseif($bad==='user-range'){$userdata['user_id']=8388608;}elseif($bad==='sid-empty'){$userdata['session_id']='';}elseif($bad==='sid-array'){$userdata['session_id']=array('a');}elseif($bad==='sid-long'){$userdata['session_id']=str_repeat('a',33);}else{$userdata['session_id']="a\0b";}$tp_factory='throw';try{nt_check(bn_rows()===array()&&!$tp_candidates&&tp_released(),'Malformed actor fails closed before factory: '.$bad);}finally{$tp_factory='normal';}$cases++;
 }
 foreach(array(0,-1,1001,array(5),'5x',true)as$limit){bn_prepare();$tp_factory='throw';try{nt_check(bn_rows($limit)===array()&&!$tp_candidates&&tp_released(),'Invalid/disabled header size opens no connection');}finally{$tp_factory='normal';}$cases++;}
 bn_prepare();$userdata['session_logged_in']=false;$nav_links=array('bookmark'=>array(array('title'=>'Stale','url'=>'viewtopic.php?t=999')));$before=tp_snapshot();nt_check(bn_header($header)===array()&&!$tp_candidates&&tp_snapshot()===$before&&tp_released(),'Actual guest header clears all old bookmark titles without opening storage');$cases++;
 bn_prepare();$nav_links=array('bookmark'=>array(array('title'=>'Stale','url'=>'viewtopic.php?t=999')));$tp_factory='throw';$before=tp_snapshot();try{nt_check(bn_header($header)===array()&&tp_snapshot()===$before&&tp_released(),'Actual header stays available and clears stale links after optional reader failure');}finally{$tp_factory='normal';}$cases++;
 foreach(array('missing','borrow','throw','busy')as$factory){
  bn_prepare();if($factory==='busy'){$tp_busy=$db->sql_dedicated_connection();nt_check($tp_busy->inner->inner->sql_query('START TRANSACTION')&&$tp_busy->inner->inner->sql_query("UPDATE fixture_users SET username='Borrowed nav' WHERE user_id=10"),'Canonical borrowed fixture seed succeeds');}$tp_factory=$factory;$before=tp_snapshot();
  try{nt_check(bn_rows()===array()&&tp_snapshot()===$before&&$db->db_connect_id,'Unsafe factory exposes no titles and changes no caller data: '.$factory);if($factory==='busy'){$r=$tp_busy->inner->inner->sql_query('SELECT username FROM fixture_users WHERE user_id=10');$row=$tp_busy->inner->inner->sql_fetchrow($r);$tp_busy->inner->inner->sql_freeresult($r);nt_check($row['username']==='Borrowed nav'&&tp_rows('SELECT username FROM fixture_users WHERE user_id=10')[0]['username']!=='Borrowed nav','Borrowed transaction remains open and private');}}finally{$tp_factory='normal';if($factory==='busy'){$tp_busy->inner->inner->sql_query('ROLLBACK');$tp_busy->sql_close();}}nt_check(tp_released(),'Only adopted reader connections are released');$cases++;
 }
 foreach(array('config','sessions','users','banlist','bookmarks','forums','topics','posts','user_group','auth_access')as$table){bn_prepare();tn_sql('ALTER TABLE fixture_'.$table.' ENGINE=MyISAM');try{$before=tp_snapshot();nt_check(bn_rows()===array()&&tp_snapshot()===$before&&tp_released(),'Every participant must be canonical transactional storage: '.$table);}finally{tn_sql('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}$cases++;}
 bn_prepare();$trace=array();$tp_hook=function($phase,$sql)use(&$trace){if($phase==='before'){$trace[]=$sql;}};nt_check(bn_ids(bn_rows())===array(100),'Successful reader trace');$tp_hook=null;$positions=array();$owned=false;
 foreach($trace as$i=>$sql){if($sql==='SET SESSION lock_wait_timeout=3'){$owned=true;}if($owned&&$sql!=='ROLLBACK'&&strpos($sql,'SELECT CONNECTION_ID()')!==0){$positions[$i+1]=$sql;}}
 foreach($positions as$position=>$sql){foreach(array('before','after')as$phase){bn_prepare();$before=tp_snapshot();$seen=0;$reached=false;$tp_hook=function($event,$statement)use($position,$phase,&$seen,&$reached){if($event==='before'){$seen++;}if($event===$phase&&$seen===$position){$reached=true;return false;}};try{nt_check(bn_rows()===array()&&$reached&&tp_snapshot()===$before&&tp_released(),'Every adopted reader SQL boundary fails closed and leaves native rows unchanged');}finally{$tp_hook=null;}$faults++;}}
 bn_prepare();$changed=false;$tp_hook=function($phase,$sql)use(&$changed){if($phase==='after'&&strpos($sql,'SELECT a.forum_id,')===0){$GLOBALS['tp_hook']=null;tn_sql('UPDATE fixture_forums SET auth_view=5 WHERE forum_id=3');tn_sql('DELETE FROM fixture_bookmarks WHERE user_id=7 AND topic_id=100');$changed=true;}};
 try{nt_check(bn_ids(bn_rows())===array(100)&&$changed&&tp_released(),'Real independent writer can complete while reader retains one coherent prior snapshot');nt_check(bn_rows()===array()&&tp_released(),'The next snapshot observes completed revocation/removal');}finally{$tp_hook=null;}$cases++;
 bn_prepare();$blocked=false;$tp_hook=function($phase,$sql)use(&$blocked){if($phase==='after'&&strpos($sql,'SELECT u.user_id,u.user_level,')===0){$GLOBALS['tp_hook']=null;$old=tn_rows('SELECT @@SESSION.lock_wait_timeout value');tn_sql('SET SESSION lock_wait_timeout=1');try{$r=$GLOBALS['tn_peer']->sql_query("ALTER TABLE fixture_banlist COMMENT='Synthetic navigation metadata probe'");$e=$GLOBALS['tn_peer']->sql_error();$blocked=$r===false&&(int)$e['code']===1205;}finally{tn_sql('SET SESSION lock_wait_timeout='.(int)$old[0]['value']);}}};
 try{nt_check(bn_ids(bn_rows())===array(100)&&$blocked&&tp_released(),'Real peer cannot change even an empty participant schema during the canonical read-only snapshot');}finally{$tp_hook=null;tn_sql("ALTER TABLE fixture_banlist COMMENT=''");}$cases++;
 echo 'Native bookmark navigation: '.$cases.' cases, '.$faults." SQL faults PASS; actual header/search pages, unique stable counts, current view/read/session/account, coherent read-only snapshots, safe factories and EN/DE.\n";
}
