<?php
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$root=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$root;$phpEx='php';$table_prefix='fixture_';require$root.'includes/php_compat.php';require$root.'includes/constants.php';require$root.'pafiledb/includes/pafiledb_constants.php';require$root.'pafiledb/includes/functions_field.php';
function cr_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cr_checks']++;}
class CustomReadTemplate{public$rows=array();function assign_block_vars($block,$values){$this->rows[$block][]=$values;}}
class CustomReadDatabase{function sql_query($sql){throw new RuntimeException('Viewing a field attempted database I/O');}}
$cr_checks=0;$db=new CustomReadDatabase();$pafiledb_template=new CustomReadTemplate();
cr_check(CHECKBOX===3&&PA_FIELD_SELECT===3&&PA_FIELD_CHECKBOX===5&&count(array_unique(array(PA_FIELD_INPUT,PA_FIELD_TEXTAREA,PA_FIELD_RADIO,PA_FIELD_SELECT,PA_FIELD_SELECT_MULTIPLE,PA_FIELD_CHECKBOX)))===6,'Actual profile constants never change the six Download storage type IDs');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $fields=new custom_field();$choice="O'Reilly \\ & <tag> Grüße 😀";$encoded=htmlspecialchars($choice,ENT_QUOTES,'UTF-8');
 foreach(array(1=>PA_FIELD_INPUT,2=>PA_FIELD_TEXTAREA,3=>PA_FIELD_RADIO,4=>PA_FIELD_SELECT,5=>PA_FIELD_SELECT_MULTIPLE,6=>PA_FIELD_CHECKBOX,7=>PA_FIELD_INPUT,8=>999)as$id=>$type){$fields->field_rowset[$id]=array('custom_id'=>$id,'custom_name'=>'Synthetic '.$id,'custom_description'=>'Description','field_type'=>$type,'data'=>serialize(array('0',$encoded)));}
 foreach(array(1=>'0',2=>'0',3=>'0',4=>'0',5=>serialize(array('0',$choice)),6=>serialize(array('0',$choice)),7=>'',8=>'unknown',99=>'orphan')as$id=>$data){$fields->field_data_rowset[42][$id]=array('customdata_file'=>42,'customdata_custom'=>$id,'data'=>$data);}
 $before=$fields->field_data_rowset;$fields->display_data(42);$rows=$pafiledb_template->rows['custom_field'];cr_check(count($rows)===6&&$fields->field_data_rowset===$before,'Viewing zero, empty, unknown and orphaned custom fields is read-only');
 for($i=0;$i<4;$i++){cr_check($rows[$i]['DATA']==='0','Literal zero remains visible for scalar type '.$i);}
 foreach(array(4,5)as$i){cr_check(html_entity_decode($rows[$i]['DATA'],ENT_QUOTES,'UTF-8')==='0, '.$choice&&strpos($rows[$i]['DATA'],'<tag>')===false,'Raw multi-value data is escaped once on output');}
 $pafiledb_template=new CustomReadTemplate();$fields->display_edit(42);foreach(array('input','textarea')as$kind){cr_check($pafiledb_template->rows[$kind][0]['FIELD_VALUE']==='0','Edit keeps zero for '.$kind);}
 foreach(array('radio.row','select.row')as$kind){cr_check(strpos($pafiledb_template->rows[$kind][0]['FIELD_SELECTED'],'checked')!==false||strpos($pafiledb_template->rows[$kind][0]['FIELD_SELECTED'],'selected')!==false,'Scalar choice zero remains selected');}
 foreach(array('select_multiple.row'=>'FIELD_SELECTED','checkbox.row'=>'FIELD_CHECKED')as$kind=>$binding){$rows=$pafiledb_template->rows[$kind];cr_check(count($rows)===2&&$rows[1]['FIELD_VALUE']===$encoded&&$rows[0][$binding]!==''&&$rows[1][$binding]!=='','Actual choices preserve serialized slashes and compare decoded raw values');}
 cr_check($fields->get_field_data(5)['data']==='0' . "\n" . $encoded,'Definition editor preserves serialized choice bytes without stripping backslashes');
 foreach(array('0',$choice,'&amp;')as$draft){$_POST=array('field'=>array(1=>$draft,2=>$draft,3=>$draft,4=>$draft,5=>array('0',$choice),6=>array($choice)));if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$_POST=phpbb_addslashes_recursive($_POST);}$fields->adopt_file_draft(42);$pafiledb_template=new CustomReadTemplate();$fields->display_edit(42);
  foreach(array('input','textarea')as$kind){cr_check(html_entity_decode($pafiledb_template->rows[$kind][0]['FIELD_VALUE'],ENT_QUOTES,'UTF-8')===$draft,'Rejected custom draft round trips raw text: '.$kind);}
  cr_check(strpos($pafiledb_template->rows['checkbox.row'][1]['FIELD_CHECKED'],'checked')!==false,'Rejected multiple choice retains decoded selection');
 }
 $_POST=array();$fields->adopt_file_draft(42);cr_check($fields->field_data_rowset[42][5]['data']===''&&$fields->field_data_rowset[42][6]['data']==='','Rejected unchecked multi-value draft clears stale selections');
 echo 'Download custom-field rendering: ',$cr_checks," actual read-only/zero/choice/escaping/draft checks PASS (",defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
