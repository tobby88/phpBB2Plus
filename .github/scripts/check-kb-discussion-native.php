<?php
$kp_mirror_mode=true;
class KmNativeTemplate{function make_filename($file){if($file!=='bbcode.tpl'){throw new RuntimeException('Only standard BBCode rendering');}return$GLOBALS['kp_source'].'templates/fisubsilversh/bbcode.tpl';}}
function km_input(){ $input=kw_input();$input['title']='Title [b]literal[/b] <b>HTML</b> &amp; Grüße';$input['description']='Description [img]https://metadata.example.invalid/p.png[/img] <b>HTML</b> www.metadata.example.invalid mail@example.invalid '.chr(34).'quotes'.chr(34);$input['body']='Intended [b]body[/b] <b>body-html</b>';return$input; }
function km_seed($actor){kw_reset($actor,true);kp_sql("UPDATE fixture_kb_categories SET category_name='Category [i]literal[/i] &amp; Grüße' WHERE category_id=2");kp_sql("UPDATE fixture_kb_types SET type='Type [b]literal[/b]' WHERE id=1");foreach(array(1=>'Root [b]x[/b] &amp; @.z',4=>'Author [i]name[/i] &amp;')as$id=>$name){kp_sql("UPDATE fixture_users SET username='".$GLOBALS['peer']->sql_escape($name)."' WHERE user_id=".$id);}}
function km_root(){ $GLOBALS['userdata']['user_id']=1;$GLOBALS['userdata']['session_logged_in']=true;$GLOBALS['userdata']['session_admin']=1;kp_sql('UPDATE fixture_sessions SET session_user_id=1,session_logged_in=1,session_admin=1'); }
function km_guest(){ $GLOBALS['userdata']['user_id']=ANONYMOUS;$GLOBALS['userdata']['session_logged_in']=false;$GLOBALS['userdata']['session_admin']=0;kp_sql('UPDATE fixture_sessions SET session_user_id=-1,session_logged_in=0,session_admin=0');kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='allow_anon'"); }
function km_posts($id,$author,$expected_count){
 global$lang;
 $articles=kp_rows('SELECT * FROM fixture_kb_articles WHERE article_id='.(int)$id);kp_check(count($articles)===1,'Owned article survives formatting');$article=$articles[0];
 $posts=kp_rows('SELECT p.post_id,p.enable_html,p.enable_bbcode,p.enable_smilies,p.post_username,t.bbcode_uid,t.post_text FROM fixture_posts p INNER JOIN fixture_posts_text t ON t.post_id=p.post_id WHERE p.topic_id='.(int)$article['topic_id'].' ORDER BY p.post_id');kp_check(count($posts)===$expected_count,'Discussion creation/edit/bump count unchanged');
 foreach($posts as$post){
  kp_check($post['enable_html']==='0'&&$post['enable_bbcode']==='1'&&$post['enable_smilies']==='0','Generated summaries use HTML0/BBCode1/smilies0, including rebuilt first posts');
  $html=make_clickable(bbencode_second_pass($post['post_text'],$post['bbcode_uid']));$visible=html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8');
  kp_check(substr_count($html,'<a ')===1&&stripos($html,'<img')===false&&strpos($html,'[b:')===false,'Actual standard template keeps only generated link, no metadata images/links or unrendered trusted tags');
  kp_check(strpos($html,'href="https://forum.example.invalid/kb.php?mode=article&amp;k='.(int)$id.'"')!==false,'Actual generated article link remains correctly encoded');
  foreach(array(html_entity_decode($article['article_title'],ENT_QUOTES,'UTF-8'),html_entity_decode($article['article_description'],ENT_QUOTES,'UTF-8'),$lang['Author'].': '.$author,'Category [i]literal[/i] & Grüße')as$text){kp_check(strpos($visible,$text)!==false,'Displayed literal summary value survives exact Unicode/entities/markup roundtrip: '.$text);}
  $type=(int)$article['article_type']>0?'Type [b]literal[/b]':$lang['None'];kp_check(strpos($visible,$lang['Article_type'].': '.$type)!==false,'Type remains literal or uses no-assignment label');
 }
 return array($article,$posts);
}
function kp_mirror_suite(){
 global$kp_source,$lang,$userdata,$template,$phpEx;require$kp_source.'includes/functions_kb_write_storage.php';$template=new KmNativeTemplate();$saved_lang=$lang;$cases=0;
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){fwrite(STDERR,'Discussion fixture warning: '.$message."\n");throw new RuntimeException($message);}});
 foreach(array('english','german')as$locale){require$kp_source.'language/lang_'.$locale.'/lang_main.php';require$kp_source.'language/lang_'.$locale.'/lang_admin.php';require$kp_source.'language/lang_'.$locale.'/lang_kb.php';
  foreach(array(false,true)as$body_format){foreach(array('immediate','approved','edit','quiet')as$stage){
   km_seed($stage==='approved'?'ordinary':'root');$input=km_input();$input['html_on']=$input['bbcode_on']=$input['smilies_on']=$body_format;$out=kw_ok(kw_run('add',$input),'Actual summary seed '.$locale.'/'.$stage);$author=$stage==='approved'?'Author [i]name[/i] &amp;':'Root [b]x[/b] &amp; @.z';
   if($stage==='approved'){km_root();kp_check(is_array(kp_run(1,'acp',$out['article_id'])),'Actual pending approval discussion');}
   if($stage==='edit'||$stage==='quiet'){$input['title'].=' edited';$author='Editor [i]x[/i] &amp;';kp_sql("UPDATE fixture_users SET username='".$GLOBALS['peer']->sql_escape($author)."' WHERE user_id=1");if($stage==='quiet'){kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name='bump_post'");}kw_ok(kw_run('edit',$input,$out['article_id']),'Actual existing discussion update');}
   $result=km_posts($out['article_id'],$author,$stage==='edit'?2:1);kp_check(($result[0]['bbcode_uid']!=='')===$body_format,'Article body formatting choice remains independent of literal metadata');$cases++;
  }}
  foreach(array(false,true)as$approval){foreach(array('Synthetic Gast Grüße','0','', 'Guest &amp; [b]x[/b]')as$name){
   km_seed('root');km_guest();if(!$approval){kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name='approve_new'");}$input=km_input();$input['username']=$name;$out=kw_ok(kw_run('add',$input),'Actual guest summary');
   if($approval){km_root();kp_check(is_array(kp_run(1,'acp',$out['article_id'])),'Actual pending guest approval');}
   $result=km_posts($out['article_id'],$name!==''?$name:$lang['Guest'],1);kp_check($result[0]['article_author_id']==='0'&&$result[0]['username']===$name,'Guest saved identity remains intact');$cases++;
  }}
  km_seed('ordinary');$input=km_input();$out=kw_ok(kw_run('add',$input),'Missing old registered author seed');km_root();kp_sql('DELETE FROM fixture_users WHERE user_id=4');kp_check(is_array(kp_run(1,'acp',$out['article_id'])),'Current admin may approve old article whose author no longer exists');km_posts($out['article_id'],'Author [i]name[/i] &amp;',1);$cases++;
  km_seed('ordinary');$input=km_input();$out=kw_ok(kw_run('add',$input),'Old no-type article seed');kp_sql('UPDATE fixture_kb_articles SET article_type=0 WHERE article_id='.(int)$out['article_id']);km_root();kp_check(is_array(kp_run(1,'acp',$out['article_id'])),'Existing supported no-type article approval');km_posts($out['article_id'],'Author [i]name[/i] &amp;',1);$cases++;
 }
 $lang=$saved_lang;
 foreach(array(str_repeat('😀',255),str_repeat('&',255),str_repeat(chr(34),255),str_repeat('<b>Grüße</b>',30))as$title){
  km_seed('root');$input=km_input();$input['title']=$title;$out=kw_ok(kw_run('add',$input),'Long/escaped generated forum subject');km_posts($out['article_id'],'Root [b]x[/b] &amp; @.z',1);
  $rows=kp_rows('SELECT t.topic_title,p.post_subject FROM fixture_topics t INNER JOIN fixture_posts_text p ON p.post_id=t.topic_first_post_id');kp_check(count($rows)===1&&$rows[0]['topic_title']===$rows[0]['post_subject'],'Topic and first-post subjects remain identical');$subject=$rows[0]['topic_title'];kp_check(preg_match_all('/./us',$subject,$characters)<=60&&preg_match('//u',$subject)===1&&!preg_match('/&[^;]*$/D',$subject),'Stored subject fits both 60-character columns without splitting Unicode/entities');$cases++;
 }
 foreach(array(false,true)as$bump){
  km_seed('root');$input=km_input();$out=kw_ok(kw_run('add',$input),'Unrelated reply preservation seed');$article=kp_rows('SELECT topic_id FROM fixture_kb_articles WHERE article_id='.(int)$out['article_id']);$topic=(int)$article[0]['topic_id'];
  kp_sql('INSERT INTO fixture_posts(topic_id,forum_id,poster_id,post_time,poster_ip,enable_html,enable_bbcode,enable_smilies) VALUES('.$topic.",1,4,123,'7f000001',1,0,1)");$reply=(int)$GLOBALS['peer']->sql_nextid();kp_sql("INSERT INTO fixture_posts_text(post_id,post_subject,post_text,bbcode_uid) VALUES(".$reply.",'Unrelated','Keep <b>Grüße</b> [i]text[/i]','')");$before=array(kp_rows('SELECT * FROM fixture_posts WHERE post_id='.$reply),kp_rows('SELECT * FROM fixture_posts_text WHERE post_id='.$reply));
  kp_sql("UPDATE fixture_kb_config SET config_value='".($bump?'1':'0')."' WHERE config_name='bump_post'");$input['title'].=' changed';kw_ok(kw_run('edit',$input,$out['article_id']),'Rebuild with unrelated discussion reply');kp_check($before===array(kp_rows('SELECT * FROM fixture_posts WHERE post_id='.$reply),kp_rows('SELECT * FROM fixture_posts_text WHERE post_id='.$reply)),'Unrelated reply text, identity and rendering flags remain byte-for-byte intact');$cases++;
 }
 foreach(array(false,true)as$bump){
  km_seed('root');$input=km_input();$out=kw_ok(kw_run('add',$input),'Foreign first-post rejection seed');kp_sql("INSERT INTO fixture_posts(topic_id,forum_id,poster_id,post_time,poster_ip) VALUES(999,1,4,123,'7f000001')");$foreign=(int)$GLOBALS['peer']->sql_nextid();kp_sql('UPDATE fixture_topics SET topic_first_post_id='.$foreign);kp_sql("UPDATE fixture_kb_config SET config_value='".($bump?'1':'0')."' WHERE config_name='bump_post'");$before=kp_snapshot();kp_check(kw_run('edit',$input,$out['article_id']) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Foreign first-post relation refuses without any article/forum mutation');$cases++;
 }
 // Old generated first posts used HTML1/smilies1. Rebuilding their owned
 // summary must update flags too, without touching unrelated discussion replies.
 foreach(array(false,true)as$bump){km_seed('root');$input=km_input();$out=kw_ok(kw_run('add',$input),'Old flag seed');kp_sql('UPDATE fixture_posts SET enable_html=1,enable_smilies=1');kp_sql("UPDATE fixture_kb_config SET config_value='".($bump?'1':'0')."' WHERE config_name='bump_post'");$input['title'].=' changed';kw_ok(kw_run('edit',$input,$out['article_id']),'Rebuild legacy generated first post');km_posts($out['article_id'],'Root [b]x[/b] &amp; @.z',$bump?2:1);$cases++;}
 echo 'KB discussion native: '.$cases." actual immediate/approval/edit/bump/guest/profile/legacy-flags/body-format/locale/render cases passed. No external metadata marker fetched.\n";
}
require __DIR__.'/check-kb-writing-native.php';
