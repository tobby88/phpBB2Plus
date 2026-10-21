<?php
// Actual canonical small local fixture, renderer/authorizer/controller. No live
// data, network, load tests or stored-post repair. Reuse existing read-only checks.
$kp_comment_retention_mode=true;
function kr_ids(){global$template;$out=array();foreach(isset($template->blocks['postrow'])?$template->blocks['postrow']:array()as$row){$out[]=(int)$row['U_POST_ID'];}return$out;}
function kr_seed(){
 kcv_seed('ordinary',AUTH_ALL,AUTH_ALL);kcv_guest();
 kp_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip,enable_html,enable_bbcode,enable_smilies) VALUES(3,77,1,4,124,'7f000001',0,0,0)");
 kp_sql("INSERT INTO fixture_posts_text(post_id,post_subject,post_text,bbcode_uid) VALUES(3,'Reply three','Synthetic reply three Grüße','')");
}
function kr_render($start=0,$size=10){
 global$db,$template;$db->queries=array();$template=new KcvTemplate();$returned=get_kb_comments(77,$start,$size);
 kp_check($returned===$template,'Existing template return contract retained');return kr_ids();
}
function kr_controller($requested,$size){
 global$db,$template,$kp_source,$kb_config,$start,$article_id,$lang,$phpbb_root_path,$phpEx;
 $source=file_get_contents($kp_source.'includes/kb_article.php');$a=strpos($source,"\$topic=array();\$comments='';");$b=strpos($source,'//rate',$a);
 kp_check($a!==false&&$b>$a,'Actual full comment controller branch');$topic_id=77;$article_id=1;$start=$requested;$template=new KcvTemplate();
 $kb_config['comments']=$kb_config['comments_show']=1;$kb_config['comments_pagination']=$size;$pagination='';eval(substr($source,$a,$b-$a));
 return array($topic,$start,$comments,$pagination,kr_ids());
}
function kp_comment_retention_suite(){
 global$db,$template,$kp_source,$board_config,$lang,$userdata;
 require$kp_source.'includes/auth.php';require$kp_source.'attach_mod/includes/functions_includes.php';
 $source=file_get_contents($kp_source.'includes/functions.php');foreach(array('phpbb_profile_text','generate_pagination')as$name){kp_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$match)===1,'Actual helper '.$name);eval($match[0]);}
 $cases=0;
 foreach(array('english','german')as$locale){require$kp_source.'language/lang_'.$locale.'/lang_main.php';require$kp_source.'language/lang_'.$locale.'/lang_kb.php';
  foreach(array('first-post-order','deleted-reply-author','deleted-first-author','missing-first','zero-first','foreign-first','foreign-forum','missing-text','stale-count','locked')as$case){
   kr_seed();$ids=array(2,3);
   if($case==='first-post-order'){kp_sql('UPDATE fixture_topics SET topic_first_post_id=2');$ids=array(1,3);}
   elseif($case==='deleted-reply-author'){kp_sql('UPDATE fixture_posts SET poster_id=9 WHERE post_id=2');}
   elseif($case==='deleted-first-author'){kp_sql('UPDATE fixture_posts SET poster_id=9 WHERE post_id=1');}
   elseif($case==='missing-first'){kp_sql('DELETE FROM fixture_posts_text WHERE post_id=1');kp_sql('DELETE FROM fixture_posts WHERE post_id=1');}
   elseif($case==='zero-first'){kp_sql('UPDATE fixture_topics SET topic_first_post_id=0');$ids=array(1,2,3);}
   elseif($case==='foreign-first'){kp_sql('UPDATE fixture_topics SET topic_first_post_id=88');$ids=array(1,2,3);}
   elseif($case==='foreign-forum'){kp_sql('UPDATE fixture_posts SET forum_id=2 WHERE post_id=2');$ids=array(3);}
   elseif($case==='missing-text'){kp_sql('DELETE FROM fixture_posts_text WHERE post_id=2');$ids=array(3);}
   elseif($case==='stale-count'){kp_sql('UPDATE fixture_topics SET topic_replies=99');}
   else{kp_sql('UPDATE fixture_topics SET topic_status='.TOPIC_LOCKED);}
   $before=kp_snapshot();$topic=kb_comment_topic(77);kp_check($topic['topic_replies']===count($ids)&&kr_render()===$ids,'Actual surviving count and replies '.$locale.'/'.$case);
   $control=kr_controller(0,10);kp_check($control[0]['topic_replies']===count($ids)&&$control[4]===$ids&&strpos($control[2],'['.count($ids).' - ')!==false,'Actual displayed count/list match '.$case);
   kp_check(kp_snapshot()===$before,'No inferred first post, account reassignment, cached-counter or content rewrite '.$case);$cases++;
  }
  $literal="Saved O'Reilly <b>😀</b> &amp; Größe";$key=phpbb_post_author_key($literal);
  foreach(array(
   array(-1,null,$key,phpbb_profile_text($literal)),
   array(9,null,$key,phpbb_profile_text($literal)),
   array(9,null,'0','0'),array(-1,null,'0','0'),
   array(9,null,'',phpbb_profile_text($lang['Guest'])),
   array(9,null,null,phpbb_profile_text($lang['Guest'])),
   array(4,'0',$key,'0'),
   array(4,'Cur &amp; Größe',$key,phpbb_profile_text('Cur &amp; Größe')),
   array(9,null,'<b>Legacy</b> & Grüße',phpbb_profile_text('<b>Legacy</b> & Grüße'))
  )as$identity){
   kr_seed();kp_sql('UPDATE fixture_posts SET poster_id='.$identity[0].',post_username='.($identity[2]===null?'NULL':"'".$GLOBALS['peer']->sql_escape($identity[2])."'").' WHERE post_id=2');
   if($identity[1]!==null){kp_sql("UPDATE fixture_users SET username='".$GLOBALS['peer']->sql_escape($identity[1])."' WHERE user_id=4");}
   $before=kp_snapshot();kp_check(kr_render()===array(2,3),'Historical identity never hides reply');$name=$template->blocks['postrow'][0]['POSTER_NAME'];
   kp_check($name===$identity[3]&&stripos($name,'<b>')===false&&strpos($name,'<a ')===false,'Current/raw and saved/escaped name boundaries, including zero, no dead profile link');
   kp_check(kp_snapshot()===$before,'Original registered/guest poster ID and stored name unchanged');$cases++;
  }
  kr_seed();
  for($id=4;$id<=18;$id++){$poster=$id%3===0?9:($id%5===0?-1:4);kp_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_username,enable_html,enable_bbcode,enable_smilies) VALUES(".$id.',77,1,'.$poster.",124,'7f000001','0',0,0,0)");kp_sql("INSERT INTO fixture_posts_text(post_id,post_subject,post_text,bbcode_uid) VALUES(".$id.",'Synthetic','Small reply ".$id."','')");}
  $expected=range(2,18);$before=kp_snapshot();$all=array();
  for($offset=0;$offset<17;$offset+=5){
   $ids=kr_render($offset,5);kp_check($ids===array_slice($expected,$offset,5),'Stable complete page including missing/guest authors');$all=array_merge($all,$ids);
   kp_check(count($db->queries)===3,'Guest page has exactly current-topic, count and page SELECTs; no per-author queries');
   $sql=$db->queries[2];kp_check(strpos($sql,' LIMIT '.$offset.',5')!==false&&strpos($sql,'LEFT JOIN fixture_users')!==false&&strpos($sql,'p.*')===false&&strpos($sql,'user_email')===false&&strpos($sql,'user_sig')===false,'Bounded narrow query excludes unused profile/signature fields');$cases++;
  }
  kp_check($all===$expected,'Every actual reply appears exactly once across all pages');
  foreach(array(array('99999999999999999999',15),array(7,5),array(-1,0),array('bad',0),array(array(5),0),array('0000005',5))as$window){
   kp_check(kr_render($window[0],5)===array_slice($expected,$window[1],5),'Malformed, huge and nonaligned windows normalize to complete valid page');$cases++;
  }
  foreach(array(array(0,20),array('bad',20),array(array(5),20),array(1,1),array(9999,1000))as$size){
   $ids=kr_render(0,$size[0]);kp_check($ids===array_slice($expected,0,$size[1])&&strpos($db->queries[2],' LIMIT 0,'.$size[1])!==false,'Every helper call, including old defaults, is bounded');$cases++;
  }
  $control=kr_controller('99999999999999999999','5');kp_check($control[0]['topic_replies']===17&&$control[1]===15&&$control[4]===array(17,18)&&strpos($control[3],'start=10')!==false,'Actual controller count, normalized last window and real core pagination links');$cases++;
  $control=kr_controller(0,array(5));kp_check($control[1]===0&&$control[4]===$expected,'Malformed configured page size defaults without warnings');$cases++;
  kp_check(kp_snapshot()===$before,'All complete pages and metadata remain read-only');
  kr_seed();kp_sql('DELETE FROM fixture_posts_text');kp_sql('DELETE FROM fixture_posts');$before=kp_snapshot();
  kp_check(kr_render('99999999999999999999',5)===array()&&kb_comment_topic(77)['topic_replies']===0&&kp_snapshot()===$before,'Empty and stale-count thread remains intact without GET repair');$cases++;
 }
 foreach(array('AS comment_count','pt.post_text')as$fault){kr_seed();$before=kp_snapshot();$db->fail=$fault;$failed=false;try{kr_render();}catch(KpMessageExit$exit){$failed=true;}kp_check($failed&&!$template->blocks&&kp_snapshot()===$before,'Count/page query faults refuse partial display without mutations');$cases++;}
 echo 'KB comment retention native: '.$cases." actual first-ID/missing-account/guest-codec/count/paging/controller/query/fault/locale/read-only cases passed.\n";
}
require __DIR__.'/check-kb-comment-visibility-native.php';
