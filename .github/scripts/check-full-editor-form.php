<?php
// Execute the actual poll-add form branch without a database or user data.
$posting=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/posting.php');
$start=strpos($posting,"\tif (isset(\$poll_add) && \$add_poll_option_text !== '')");
$end=strpos($posting,"\n\tif ( \$mode == 'newtopic' || \$mode == 'reply')",$start);
if($start===false||$end<=$start){throw new RuntimeException('Actual poll-add branch missing');}
$branch=substr($posting,$start,$end-$start);$lang=array('Full_edit_poll_options'=>'No free option ID');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array(array(array(2,3),array(1,2,3),4),array(array(2,255),array(2,255),1),array(array(),array(),1),array(array(1),array(),2),array(array(255),range(1,255),null)) as $case){
  $poll_options=array();foreach($case[0] as $id){$poll_options[$id]='Existing '.$id;}$before=$poll_options;$reserved_poll_option_ids=array_fill_keys($case[1],true);$poll_add=true;$error_msg='';$plain="Grüße O'Reilly \\ <&> 😀";$add_poll_option_text=addslashes($plain);eval($branch);
  if($case[2]===null){if($poll_options!==$before||$error_msg===''){throw new RuntimeException('Exhausted IDs must preserve draft and report error');}}
  else{if(count($poll_options)!==count($before)+1||!isset($poll_options[$case[2]])||htmlspecialchars_decode($poll_options[$case[2]],ENT_QUOTES)!==$plain||$error_msg!==''){throw new RuntimeException('New option gets an unused identity and preserves Unicode');}foreach($before as $id=>$text){if($poll_options[$id]!==$text){throw new RuntimeException('Existing option changed');}}}
 }
 echo "Full-editor poll form preserves identities, draft text and bounded allocation.\n";
}finally{restore_error_handler();}
