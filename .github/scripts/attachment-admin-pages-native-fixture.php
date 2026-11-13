<?php
// Invoked inside the existing owned-schema try/finally, using the full controller
// and actual XS form. Never connect to a forum configuration or touch its files.
uacp_list_fixture_insert($db,'fixture_users',array('user_id'=>4,'username'=>'Empty owner'));
for($id=4;$id<=13;$id++){
 uacp_list_fixture_insert($db,'fixture_attachments_desc',array('attach_id'=>$id,'physical_filename'=>'synthetic-'.$id,'real_filename'=>'File'.($id%3).'.jpg','comment'=>'Same '.($id%2),'extension'=>$id%2?'jpg':'png','filesize'=>123+$id%3,'download_count'=>$id%3,'filetime'=>1700000000+$id%2));
 aar_check($db->sql_query('INSERT INTO fixture_attachments VALUES('.$id.',501,0,'.($id===13?3:2).',0)')!==false,'Owned uploader link');
}
aar_check($db->sql_query('INSERT INTO fixture_attachments VALUES(99,501,0,2,0),(99,501,0,4,0),(4,0,603,3,2),(4,999,0,2,0)')!==false,'Owned duplicate/shared and missing-description links');
$scopes=array(0=>range(1,13),2=>array_merge(array(1,2),range(4,12)),3=>array(4,13));$cases=$faults=0;
foreach(array('english','german')as$locale){
 $lang=array();foreach(array('lang_main','lang_main_attach','lang_admin_attach')as$file){require$phpbb_root_path.'language/lang_'.$locale.'/'.$file.'.php';}
 $before=aar_snapshot();
 foreach($scopes as$uid=>$expected){
  foreach(array('real_filename','comment','extension','filesize','downloads','post_time')as$mode){foreach(array('ASC','DESC')as$order){
   $walk=array();$repeat=array();
   for($start=0;$start<count($expected);$start+=2){
    $request=array('uid'=>$uid,'mode'=>$mode,'order'=>$order,'start'=>$start);$out=aar_call($request,false,array(),0,'',2);
    aar_check($out['error']===null&&$out['total']===count($expected)&&$out['start']===$start&&count($out['rows'])===min(2,count($expected)-$start),'Actual page count and bounded offset agree with description scope');
    foreach($out['rows']as$i=>$row){aar_check($row['ROW_NUMBER']===$start+$i+1&&preg_match('/name="attach_id_list\[\]" value="([0-9]+)"/',$row['S_HIDDEN'],$m)===1,'Actual form IDs and row numbers');$walk[]=(int)$m[1];}
    if(count($expected)>2){aar_check($out['page'][1]===count($expected)&&$out['page'][2]===2&&$out['page'][3]===$start,'Actual page label and links use the same clamped count');parse_str(str_replace('&amp;','&',parse_url($out['page'][0],PHP_URL_QUERY)),$query);aar_check((int)$query['uid']===$uid&&$query['mode']===$mode&&$query['order']===$order,'Navigation retains uploader and sorting');}
    $again=aar_call($request,false,array(),0,'',2);foreach($again['rows']as$row){preg_match('/name="attach_id_list\[\]" value="([0-9]+)"/',$row['S_HIDDEN'],$m);$repeat[]=(int)$m[1];}$cases++;
   }
   $sorted=$walk;sort($sorted,SORT_NUMERIC);aar_check($sorted===$expected&&$repeat===$walk,'Every scoped description appears exactly once with stable sort ties');
  }}
  foreach(array(1,2,5,1000)as$size){foreach(array(13,20,1000000)as$offset){
   $out=aar_call(array('uid'=>$uid,'start'=>$offset),false,array(),0,'',$size);$start=(int)(floor((count($expected)-1)/$size)*$size);
   aar_check($out['error']===null&&$out['total']===count($expected)&&$out['start']===$start&&count($out['rows'])===count($expected)-$start&&$out['rows'][0]['ROW_NUMBER']===$start+1,'Stale offset returns the real final page, including a single-page list');$cases++;
  }}
  $out=aar_call(array('uid'=>$uid),false,array(),0,'',2);$protocol=$out['trace'];
  foreach(array('before','after')as$phase){foreach($protocol as$i=>$sql){$failed=aar_call(array('uid'=>$uid),false,array(),$i+1,$phase,2);aar_check($failed['hit']&&$failed['error']!==null&&$failed['html']===''&&aar_snapshot()===$before,'Every failed count/page/label query prevents success and preserves data');$faults++;}}
 }
 $empty=aar_call(array('uid'=>4,'start'=>1000000));aar_check($empty['error']===null&&$empty['total']===0&&$empty['start']===0&&!$empty['rows']&&$empty['page']===null,'Existing owner with only dangling links has an empty zero-offset view');$cases++;
 aar_check(aar_snapshot()===$before,'All complete controller page reads preserve exact owned data');
 aar_check($db->sql_query('START TRANSACTION')!==false&&$db->sql_query("UPDATE fixture_users SET user_sig='Caller pending' WHERE user_id=2")!==false,'Owned outer transaction');$out=aar_call(array('uid'=>2,'start'=>1000000));$pending=aar_rows('SELECT user_sig FROM fixture_users WHERE user_id=2');aar_check($out['error']===null&&$pending[0]['user_sig']==='Caller pending','Reader preserves pending caller work');aar_check($db->sql_query('ROLLBACK')!==false&&aar_snapshot()===$before,'Caller independently rolls back unchanged state');$cases++;
}
aar_check($db->sql_query('DELETE FROM fixture_attachments_desc')!==false,'Owned empty description inventory');$empty=aar_call(array('start'=>999));aar_check($empty['error']===null&&$empty['total']===0&&$empty['start']===0&&!$empty['rows']&&$empty['page']===null,'Empty global inventory cannot leave a phantom page');$cases++;
echo 'Attachment administration pages native: '.$cases.' actual controller/XS page cases, '.$faults." SQL failures PASS; EN/DE global and uploader scopes, dangling/shared links, six stable sorts, complete bounded walks, stale and empty offsets, row numbers/navigation and untouched caller transactions.\n";
