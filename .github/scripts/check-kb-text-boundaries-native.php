<?php
$kp_text_mode=true;
function kp_text_suite(){
 global$kp_source,$lang,$template;require$kp_source.'includes/functions_kb_write_storage.php';$template=new KmNativeTemplate();$cases=0;
 foreach(array(str_repeat('ü',26),str_repeat('😀',100),str_repeat('😀',255),str_repeat('&',255),str_repeat("'",255),str_repeat(chr(34),255),'<b>Guest literal</b> '.str_repeat('ü',60),'Guest &amp; [b]literal[/b] '.str_repeat('ü',60))as$name){foreach(array('immediate','approved','quiet','bump')as$stage){
  km_seed('root');km_guest();if($stage!=='approved'){kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name='approve_new'");}$input=km_input();$input['username']=$name;$out=kw_ok(kw_run('add',$input),'Wide guest byline '.$stage);
  if($stage==='approved'){km_root();kp_check(is_array(kp_run(1,'acp',$out['article_id'])),'Wide pending guest approval');}
  if($stage==='quiet'||$stage==='bump'){km_root();kp_sql("UPDATE fixture_kb_config SET config_value='".($stage==='bump'?'1':'0')."' WHERE config_name='bump_post'");$input['title'].=' edited';kw_ok(kw_run('edit',$input,$out['article_id']),'Wide guest article edit by current admin');}
  preg_match('/^.{0,255}/us',$name,$expected);$result=km_posts($out['article_id'],$expected[0],$stage==='bump'?2:1);kp_check($result[0]['article_author_id']==='0'&&$result[0]['username']===$expected[0],'Saved guest identity retains the full supported Unicode name');
  if($stage!=='approved'){kp_check($result[1][0]['post_username']===phpbb_post_author_key($expected[0]),'Original discussion byline retains full safely escaped guest name, not a 25-character prefix');}$cases++;
 }}
 km_seed('root');$input=km_input();$input['title']=str_repeat('😀',300);$input['description']=str_repeat('ü',300);$out=kw_ok(kw_run('add',$input),'Unicode title/description boundary');$result=km_posts($out['article_id'],'Root [b]x[/b] &amp; @.z',1);kp_check($result[0]['article_title']===str_repeat('😀',255)&&$result[0]['article_description']===str_repeat('ü',255),'Actual stored title/description boundary never splits UTF8');$cases++;
 foreach(array(str_repeat('ü',256),str_repeat('😀',256),"bad\0name")as$name){km_seed('root');km_guest();kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name='approve_new'");$input=km_input();$input['username']=$name;$before=kp_snapshot();kp_check(kw_run('add',$input) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Oversized/invalid guest identity is refused, never clipped to another name');$cases++;}
 foreach(array('add','edit')as$mode){
  km_seed('root');$input=km_input();$out=$mode==='edit'?kw_ok(kw_run('add',$input),'Narrow source seed'):array('article_id'=>1);kp_sql('ALTER TABLE fixture_posts MODIFY post_username VARCHAR(25) NULL');if($mode==='add'){km_guest();kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name='approve_new'");$input['username']=str_repeat('ü',26);}
  $before=kp_snapshot();$result=kw_run($mode,$input,$out['article_id']);kp_check($result instanceof PhpbbKbPublicationException&&$result->getMessage()===$lang['KB_publication_upgrade']&&kp_snapshot()===$before,'Old byline capacity reports required migration and rolls back the entire owned article change');kp_sql('ALTER TABLE fixture_posts MODIFY post_username VARCHAR(1530) NULL');$cases++;
 }
 echo 'KB text boundaries native: '.$cases.' actual wide guest/immediate/approval/edit/body/old-schema cases passed; mbstring '.(function_exists('mb_substr')?'enabled':'absent').".\n";
}
require __DIR__.'/check-kb-discussion-native.php';
