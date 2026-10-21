<?php
// Existing canonical disposable fixture, actual SQL reads/render functions and
// complete actual article/search display branches. No production data/network.
$kp_author_mode=true;
class KaTemplate {
 var $blocks=array();
 function assign_block_vars($name,$values){$this->blocks[$name][]=$values;}
}
function create_date($format,$time,$zone){return 'Synthetic date';}
function ka_visible($html){return html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8');}
function ka_html($html,$name,$id){
 kp_check(ka_visible($html)===$name,'Actual author display retains correct current or saved literal name: '.$name);
 kp_check(substr_count($html,'<a ')===($id>0?1:0)&&strpos($html,'<script>')===false,'Only extant registered author receives a profile link, no active name markup');
 if($id>0){kp_check(strpos($html,'u='.$id)!==false,'Profile link remains bound to the actual extant account');}
}
function ka_article_author($row){
 global$kp_source,$phpbb_root_path,$phpEx,$lang;
 $source=file_get_contents($kp_source.'includes/kb_article.php');$a=strpos($source,"\$author_id = (int) \$row['article_author_id'];");$b=strpos($source,'$art_pages =',$a);
 kp_check($a!==false&&$b>$a,'Complete actual direct article author branch');eval(substr($source,$a,$b-$a));
 kp_check($author_id===(int)$row['article_author_id'],'Display fallback never changes original article author ID used by permissions');return$author_kb_art;
}
function ka_search_author($id){
 global$db,$template,$kp_source,$phpbb_root_path,$phpEx,$lang;
 $source=file_get_contents($kp_source.'kb_search.php');$a=strpos($source,'foreach ($searchset as $article)');$b=strpos($source,'$base_url =',$a);
 kp_check($a!==false&&$b>$a,'Complete actual search rendering loop');$searchset=kp_rows('SELECT a.*,u.username AS registered_username FROM fixture_kb_articles a LEFT JOIN fixture_users u ON u.user_id=a.article_author_id WHERE a.article_id='.(int)$id);
 $orig_word=$replacement_word=array();$highlight_active='';$template=new KaTemplate();eval(substr($source,$a,$b-$a));return$template->blocks['searchresults'][0]['ARTICLE_AUTHOR'];
}
function kp_author_suite(){
 global$kp_source,$template,$board_config,$kb_config,$userdata,$is_admin,$is_block,$page_id,$lang;
 $source=file_get_contents($kp_source.'includes/functions.php');kp_check(preg_match('/^function phpbb_profile_text\(.*?^\}/ms',$source,$match)===1,'Actual profile escaping');eval($match[0]);
 require_once$kp_source.'includes/functions_kb_discussion.php';$cases=0;
 $literal="Saved O'Reilly \\ Größe 😀 <script>literal</script>";
 // Canonical article_author_id is unsigned. Check a defensive negative input
 // only in the pure selector, never insert impossible rows or alter the schema.
 $negative=kb_display_author(array('article_author_id'=>-1,'username'=>$literal),'Not a registered author');kp_check($negative===array('id'=>0,'name'=>$literal),'Nonpositive display input cannot become a registered profile');$cases++;
 foreach(array(array(0,$literal,null),array(4,'','Renamed current'),array(4,$literal,null),array(4,'',null),array(4,'0',null),array(0,'0',null),array(0,'',null),array(4,'Old name','0'),array(4,$literal,"O'Reilly \\ 😀 <b>x</b>"),array(4,$literal,''))as$case){
  kp_reset('root');$userdata['user_level']=USER;$is_admin=$is_block=false;$page_id=0;$board_config['default_dateformat']='Y';$board_config['board_timezone']=0;$kb_config['allow_edit']='1';
  if($case[2]===null){kp_sql('DELETE FROM fixture_users WHERE user_id=4');}else{$query="UPDATE fixture_users SET username='".$GLOBALS['peer']->sql_escape($case[2])."' WHERE user_id=4";$written=$GLOBALS['peer']->sql_query($query);kp_check($written,'Synthetic registered-name seed failed: '.json_encode($GLOBALS['peer']->sql_error()));}
  kp_sql('UPDATE fixture_kb_articles SET approved=1,article_author_id='.$case[0].",username='".$GLOBALS['peer']->sql_escape($case[1])."' WHERE article_id=1");
  $row=kp_rows('SELECT * FROM fixture_kb_articles WHERE article_id=1')[0];$before=kp_snapshot();$live=$case[0]>0&&is_string($case[2])&&$case[2]!=='';$name=$live?$case[2]:($case[1]!==''?$case[1]:$lang['Guest']);$id=$live?$case[0]:0;
  ka_html(ka_article_author($row),$name,$id);$cases++;
  $template=new KaTemplate();get_kb_articles(2,1,'rows');ka_html($template->blocks['rows'][0]['ARTICLE_AUTHOR'],$name,$id);$cases++;
  $template=new KaTemplate();get_kb_stats('latest',1,'rows');ka_html($template->blocks['rows'][0]['ARTICLE_AUTHOR'],$name,$id);$cases++;
  ka_html(ka_search_author(1),$name,$id);$cases++;
  kp_check(kb_discussion_author($row)===$name,'Actual discussion byline selects the same exact name');$cases++;
  kp_check(kp_snapshot()===$before,'All author rendering leaves every table and original ownership unchanged');
 }
 echo 'KB author display native: '.$cases." actual direct/list/stat/search/discussion missing/current/guest/zero/escaped-name cases passed; original IDs and all data unchanged.\n";
}
require __DIR__.'/check-kb-publication-native.php';
