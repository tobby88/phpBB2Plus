<?php
// Actual SQL owner, editor load/template fields, complete preview bodies and
// actual save controllers. Only the existing random local disposable fixture.
$kp_text_mode=true;
if(getenv('PHPBB_KB_TEXT_LEGACY')==='1'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
class KrtTemplate {
 var $vars=array();
 var $blocks=array();
 function set_filenames($files){}
 function assign_vars($values){$this->vars=array_merge($this->vars,$values);}
 function assign_var_from_handle($name,$handle){}
 function assign_block_vars($name,$values){$this->blocks[$name][]=$values;}
 function make_filename($file){if($file!=='bbcode.tpl'){throw new RuntimeException('Only actual BBCode template');}return$GLOBALS['kp_source'].'templates/fisubsilversh/bbcode.tpl';}
}
function obtain_word_list(&$original,&$replacement){$original=$replacement=array();}
function create_date($format,$time,$zone){return 'Synthetic date';}
function krt_post($input){
 $post=array('sid'=>$GLOBALS['userdata']['session_id'],'article_name'=>$input['title'],'article_desc'=>$input['description'],'message'=>$input['body'],'username'=>$input['username'],'type_id'=>$input['type'],'category_id'=>$input['category'],'cat'=>$input['category']);
 return defined('PHPBB_LEGACY_REQUEST_ESCAPED')&&PHPBB_LEGACY_REQUEST_ESCAPED?phpbb_addslashes_recursive($post):$post;
}
function krt_decode($value){return html_entity_decode($value,ENT_QUOTES,'UTF-8');}
function krt_edit_fields($row){
 global$kp_source;
 $source=file_get_contents($kp_source.'includes/kb_edit.php');$a=strpos($source,'$article_name =');$b=strpos($source,'$page_title =',$a);
 kp_check($a!==false&&$b>$a,'Complete actual stored editor load and UID removal');eval(substr($source,$a,$b-$a));
 $a=strpos($source,"'ARTICLE_TITLE' =>");$b=strpos($source,"'S_HIDDEN_FIELDS' =>",$a);
 kp_check($a!==false&&$b>$a,'Actual editor template escaping');return eval('return array('.substr($source,$a,$b-$a).');');
}
function krt_preview($mode,$input,$id){
 global$db,$lang,$phpbb_root_path,$phpEx,$kp_source;
 $template=new KrtTemplate();$GLOBALS['template']=$template;$article_id=$id;$preview=true;
 $html_on=$input['html_on'];$bbcode_on=$input['bbcode_on'];$smilies_on=false;$_POST=krt_post($input);
 $source=file_get_contents($kp_source.'includes/kb_'.$mode.'.php');$a=strpos($source,"\tif (\$preview)");$b=strpos($source,$mode==='add'?'// Submit a new article through':'// Save the existing article through',$a);
 kp_check($a!==false&&$b>$a,'Complete actual preview body');eval(substr($source,$a,$b-$a));return$template->vars;
}
function krt_controller($mode,$input,$id){
 global$db,$userdata,$kb_config,$lang,$phpbb_root_path,$phpEx,$kp_source;
 $_GET=array('k'=>(string)$id);$_POST=krt_post($input);$_POST['article_submit']='1';$_SERVER['REQUEST_METHOD']='POST';$article_submit=true;
 $html_on=$input['html_on'];$bbcode_on=$input['bbcode_on'];$smilies_on=false;
 $source=file_get_contents($kp_source.'includes/kb_'.$mode.'.php');$a=strpos($source,$mode==='add'?'// Submit a new article through':'// Save the existing article through');kp_check($a!==false,'Actual save controller');
 $body=str_replace('dirname(__FILE__)',var_export($kp_source.'includes',true),substr($source,$a));$body=preg_replace('/\?>\s*$/D','',$body);
 try{eval($body);}catch(KpMessageExit$error){kp_check($error->level===GENERAL_MESSAGE,'Actual text controller save: '.$error->getMessage());return;}
 throw new RuntimeException('Actual controller did not confirm completion');
}
function krt_views($row,$input){
 global$db,$template,$kp_source,$phpbb_root_path,$phpEx,$board_config,$kb_config,$userdata,$is_admin,$is_block,$page_id,$lang;
 $is_admin=$is_block=false;$userdata['user_level']=USER;$page_id=0;$board_config['default_dateformat']='Y';$board_config['board_timezone']=0;$kb_config['allow_edit']='1';
 $category_text='Category \\ literal <b>tag</b>';$type_text='Type \\ literal <b>tag</b>';$author_text='Author \\ literal';
 kp_sql("UPDATE fixture_kb_categories SET category_name='".$GLOBALS['peer']->sql_escape($category_text)."' WHERE category_id=2");
 kp_sql("UPDATE fixture_kb_types SET type='".$GLOBALS['peer']->sql_escape($type_text)."' WHERE id=1");kp_sql("UPDATE fixture_users SET username='".$GLOBALS['peer']->sql_escape($author_text)."' WHERE user_id=1");
 $cases=0;
 foreach(array(false,true)as$guest){
  kp_sql('UPDATE fixture_kb_articles SET article_author_id='.($guest?'0':'1').",username='".$GLOBALS['peer']->sql_escape($author_text)."' WHERE article_id=".(int)$row['article_id']);
  foreach(array('category','stats','search')as$view){
   $template=new KrtTemplate();
   if($view==='category'){get_kb_articles(2,1,'rows');$fields=end($template->blocks['rows']);}
   elseif($view==='stats'){get_kb_stats('latest',1,'rows');$fields=end($template->blocks['rows']);}
   else{
    $source=file_get_contents($kp_source.'kb_search.php');$a=strpos($source,'foreach ($searchset as $article)');$b=strpos($source,'$base_url =',$a);kp_check($a!==false&&$b>$a,'Actual complete search result loop');
    $searchset=kp_rows('SELECT a.*,u.username AS registered_username FROM fixture_kb_articles a LEFT JOIN fixture_users u ON u.user_id=a.article_author_id WHERE a.article_id='.(int)$row['article_id']);$orig_word=$replacement_word=array();$highlight_active='';eval(substr($source,$a,$b-$a));$fields=$template->blocks['searchresults'][0];$fields['ARTICLE']=$fields['ARTICLE_TITLE'];$fields['CATEGORY']=$fields['ARTICLE_CATEGORY'];
   }
   kp_check(krt_decode(strip_tags($fields['ARTICLE']))===krt_decode($input['title'])&&krt_decode(strip_tags($fields['ARTICLE_DESCRIPTION']))===krt_decode($input['description']),'Actual '.$view.' metadata rendering retains slashes while still escaping markup');
   kp_check(krt_decode(strip_tags($fields['ARTICLE_AUTHOR']))===$author_text&&krt_decode(strip_tags($fields['ARTICLE_TYPE']))===$type_text,'Actual '.$view.' registered/guest identity and type rendering retains slashes without active metadata HTML');
   if($view==='search'){kp_check(krt_decode(strip_tags($fields['CATEGORY']))===$category_text,'Actual search category rendering preserves literal slashes and HTML');}
   $cases++;
  }
 }
 return$cases;
}
function kp_text_suite(){
 global$kp_source,$template;
 require$kp_source.'includes/functions_kb_write_storage.php';
 $function_source=file_get_contents($kp_source.'includes/functions.php');kp_check(preg_match('/^function phpbb_profile_text\(.*?^\}/ms',$function_source,$match)===1,'Actual raw profile/form escaping');eval($match[0]);
 $cases=0;
 foreach(array(false,true)as$html){foreach(array(false,true)as$bbcode){foreach(array(false,true)as$comments){
  kw_reset('root',$comments);$template=new KrtTemplate();$input=kw_input();
  $input['title']="Title C:\\Temp\\file O'Reilly 😀 ".chr(34).'quote';
  $input['description']="Description \\ literal \\\\ pair &amp;";
  $input['body']="Path C:\\Temp\\file O'Reilly 😀 and pair \\\\ end\n[b]Bold[/b] &amp; literal <b>HTML</b> <script>literal</script>";
  $input['html_on']=$html;$input['bbcode_on']=$bbcode;
  $out=kw_ok(kw_run('add',$input),'Actual raw writer text seed');$id=(int)$out['article_id'];
  $row=kp_rows('SELECT * FROM fixture_kb_articles WHERE article_id='.$id)[0];$before=kp_snapshot();$fields=krt_edit_fields($row);
  kp_check(krt_decode($fields['ARTICLE_TITLE'])===$input['title']&&krt_decode($fields['ARTICLE_DESC'])===$input['description']&&krt_decode($fields['ARTICLE_BODY'])===$input['body'],'Actual GET editor preserves every literal slash/quote/Unicode/entity and removes only trusted BBCode UID');
  kp_check(kp_snapshot()===$before,'Editor load cannot mutate article or discussion');$cases++;
  $changed_format=$input;$changed_format['bbcode_on']=!$bbcode;$before=kp_snapshot();$changed_preview=krt_preview('edit',$changed_format,$id);
  kp_check((strpos($changed_preview['PREVIEW_MESSAGE'],'<span style="font-weight:bold">Bold</span>')!==false)===$changed_format['bbcode_on']&&kp_snapshot()===$before,'Actual edit preview honors newly toggled BBCode independently of the old saved UID');$cases++;
  foreach(array('add','edit')as$mode){
   $before=kp_snapshot();$preview=krt_preview($mode,$input,$id);
   kp_check(krt_decode($preview['ARTICLE_TITLE'])===$input['title']&&krt_decode($preview['ARTICLE_DESC'])===$input['description']&&krt_decode($preview['ARTICLE_BODY'])===$input['body'],'Actual preview retains exact redisplayed draft for '.$mode);
   kp_check(strpos(krt_decode(strip_tags($preview['PREVIEW_MESSAGE'])),"C:\\Temp\\file O'Reilly 😀 and pair \\\\ end")!==false,'Actual preview body keeps slashes and quotes, not only textarea');
   kp_check(kp_snapshot()===$before,'Actual preview reads only');$cases++;
   $posted=$input;foreach(array('title','description','body')as$key){$posted[$key]=krt_decode($preview['ARTICLE_'.($key==='title'?'TITLE':($key==='description'?'DESC':'BODY'))]);}
   krt_controller($mode,$posted,$id);$rows=kp_rows('SELECT * FROM fixture_kb_articles ORDER BY article_id DESC');$saved=$rows[0];
   if($mode==='edit'){$saved=kp_rows('SELECT * FROM fixture_kb_articles WHERE article_id='.$id)[0];}
   kp_check($saved['article_title']===$input['title']&&$saved['article_description']===$input['description'],'Actual controller strips legacy input quoting exactly once, separately from SQL escaping');
   $again=krt_edit_fields($saved);kp_check(krt_decode($again['ARTICLE_BODY'])===$input['body'],'Preview/controller/save/reopen complete body roundtrip');$cases++;
  }
  // Repeated no-op editing must never add/remove a layer of quoting.
  for($i=0;$i<3;$i++){krt_controller('edit',$input,$id);$row=kp_rows('SELECT * FROM fixture_kb_articles WHERE article_id='.$id)[0];$fields=krt_edit_fields($row);kp_check(krt_decode($fields['ARTICLE_BODY'])===$input['body']&&krt_decode($fields['ARTICLE_TITLE'])===$input['title'],'Repeated editing remains byte-stable');$cases++;}
  $cases+=krt_views($row,$input);
 }}}
 echo 'KB literal text roundtrip native: '.$cases.' actual writer/editor/preview/controller/repeated-save cases passed; legacy request quoting '.(defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'enabled':'absent').". No live data or network.\n";
}
require __DIR__.'/check-kb-writing-native.php';
