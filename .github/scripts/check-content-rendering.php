<?php
define('IN_PHPBB', true); define('WORDS_TABLE','fixture_words'); define('ACRONYMS_TABLE','fixture_acronyms'); define('CACHE_WORDS',true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/functions.php'; require $phpbb_root_path . 'includes/bbcode.php';
function cr_check($ok,$message) {if(!$ok){throw new RuntimeException($message);}}
class ContentRenderDatabase {
 var $words=array(); var $acronyms=array(); var $results=array(); var $queries=0;
 function sql_query($sql) { $this->queries++; $id=count($this->results); $this->results[$id]=strpos($sql,WORDS_TABLE)!==false?$this->words:$this->acronyms; return $id+1; }
 function sql_fetchrow($result) {return array_shift($this->results[$result-1]);}
 function sql_fetchrowset($result) {return $this->results[$result-1];}
 function sql_freeresult($result) {$this->results[$result-1]=array();return true;}
}
$db=new ContentRenderDatabase();
$db->words=array(array('word'=>'Ärger','replacement'=>'Größe \\1 $1 &amp;'),array('word'=>'a\\b','replacement'=>'Literal \\ $0'),array('word'=>'wild*','replacement'=>'Wildcard'));
$orig=$replacement=array(); obtain_word_list($orig,$replacement);
cr_check(preg_replace($orig,$replacement,'Ärger a\\b wildcard')==='Größe \\1 $1 &amp; Literal \\ $0 Wildcard','Raw Unicode, literal word backslashes, wildcard and literal replacement references');
$queries=$db->queries; obtain_word_list($orig,$replacement); cr_check($db->queries===$queries,'Rules are reused only within this request');
unset($global_orig_word,$global_replacement_word); $db->words=array(); obtain_word_list($orig,$replacement);
cr_check(!$orig&&!$replacement&&$db->queries===$queries+1,'Empty/current DB rules are fetched even when legacy file cache is enabled');
$descriptions=array('LONGTOKEN'=>'ÄÖ','ÄÖ'=>'Größe \\1 $1 &amp; " <script>','A#B'=>'Delimiter #','A&B'=>'Ampersand','A&amp;B'=>'Literal entity','D\\B'=>'Backslash');
foreach($descriptions as $name=>$description){$db->acronyms[]=array('acronym'=>$name,'description'=>$description);}
foreach($descriptions as $name=>$description){
 $visible=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $expected='<acronym title="'.htmlspecialchars($description,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.$visible.'</acronym>';
 cr_check(acronym_pass($visible)===$expected,'Literal safe acronym match/render: '.$name);
 cr_check(acronym_pass('<code>'.$visible.'</code>')==='<code>'.$visible.'</code>','Code source not transformed');
 cr_check(acronym_pass('<a title="'.$visible.'">outside</a>')==='<a title="'.$visible.'">outside</a>','HTML attributes not transformed');
 cr_check(acronym_pass($expected)===$expected,'Existing acronym markup not transformed again');
}
cr_check(acronym_pass("broken \xc3 LONGTOKEN")==="broken \xc3 LONGTOKEN",'Invalid historical UTF-8 preserves prose instead of blanking it');
echo "Content rendering checks passed.\n";
