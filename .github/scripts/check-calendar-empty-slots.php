<?php
// Run the actual cell/template binding. No forum/bootstrap/database needed.
$app=dirname(dirname(__DIR__)).'/phpBB2/';
$source=file_get_contents($app.'includes/functions_calendar.php');
$a=strpos($source,'$ind = $map[$offset_date][$k];');$b=$a===false?false:strpos($source,'$flag =',$a);
if($a===false||$b===false){throw new RuntimeException('Actual calendar cell binding unavailable');}$branch=substr($source,$a,$b-$a);
class CalendarEmptySlotTemplate
{
    public $rows=array();
    function assign_block_vars($name,$values)
    {
        if($name!=='_calendar_box._row._cell.switch_filled._event'){throw new RuntimeException('Calendar root cell block changed');}
        $this->rows[]=$values;
    }
}
$events=array(
    array('event_link'=>'viewtopic.php?t=1&amp;sid=fixture','event_type_icon'=>'<img src="icon.gif" alt="" />','event_short_title'=>'Grüße &amp; Äpfel','event_txt_class'=>'genmed','event_message'=>'&lt;b&gt;One&lt;/b&gt;'),
    array('event_link'=>'profile.php?u=2','event_type_icon'=>'','event_short_title'=>'O&#039;Reilly','event_txt_class'=>'gensmall','event_message'=>'&lt;span&gt;Two&lt;/span&gt;')
);
$cases=0;$checks=0;error_reporting(E_ALL);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
foreach(array(array(-1),array(0),array(-1,-1),array(0,-1,1,-1),array(-1,1,-1,0,-1,-1))as$indices)
{
    $template=new CalendarEmptySlotTemplate();$offset_date=1;$map=array(1=>$indices);
    foreach($indices as$k=>$expected_index)
    {
        eval($branch);$cases++;
        if(count($template->rows)!==$k+1){throw new RuntimeException('Each calendar slot needs exactly one root template row');}$checks++;
        $row=$template->rows[$k];
        if($expected_index===-1)
        {
            if($row!==array()){throw new RuntimeException('Empty calendar slot inherited fields from an adjacent event');}$checks++;
        }
        else
        {
            $event=$events[$expected_index];
            if($row['U_EVENT']!==$event['event_link']||$row['EVENT_TITLE']!==$event['event_short_title']||$row['EVENT_MESSAGE']!==$event['event_message']||$row['EVENT_CLASS']!==$event['event_txt_class']||$row['EVENT_TYPE']!==stripslashes($event['event_type_icon'])){throw new RuntimeException('Valid calendar event fields changed');}$checks++;
        }
    }
}
restore_error_handler();
echo 'Calendar empty slots: ',$cases,' actual cell cases, ',$checks," assertions PASS; valid, empty, repeated and alternating multi-day positions.\n";
