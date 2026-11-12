<?php
// Existing full-editor fixture owns this canonical schema and its cleanup.
if(PHP_SAPI!=='cli'||!isset($schema)||preg_match('/^codex_full_editor_[a-f0-9]{16}$/D',$schema)!==1){throw new RuntimeException('Owned calendar writer fixture required');}
error_reporting(E_ALL);
$calendar_cases=0;
foreach(array('english','german')as$locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';
    foreach(array(array(0,0),array(1234567890,3599),array(2147483647,0),array(1234567890,2147483647))as$values){
        ps_fixture();$out=ps_submit('newtopic',array('calendar_time'=>$values[0],'calendar_duration'=>$values[1]));
        ae_check(is_array($out),'Canonical calendar event creation must succeed');$row=ae_rows('SELECT topic_calendar_time,topic_calendar_duration FROM fixture_topics WHERE topic_id='.(int)$out['topic_id'])[0];
        ae_check((string)$row['topic_calendar_time']===(string)$values[0]&&(string)$row['topic_calendar_duration']===(string)$values[1],'Native creation stores exact integral calendar values');$calendar_cases++;
        fe_fixture();$out=fe_submit(array('calendar'=>$values[0],'calendar_duration'=>$values[1]));$row=ae_rows('SELECT topic_calendar_time,topic_calendar_duration FROM fixture_topics WHERE topic_id=100')[0];
        ae_check(is_array($out)&&(string)$row['topic_calendar_time']===(string)$values[0]&&(string)$row['topic_calendar_duration']===(string)$values[1],'Full edit stores exact integral event values');$calendar_cases++;
    }
    foreach(array(array(-1,0),array(2147483648.0,0),array(1234567890,-1),array(1234567890,2147483648.0),array(0,1),array('1e2',0),array(array(1),0),array('1234567890 OR 1=1',0))as$values){
        ps_fixture();$before=ps_snapshot();$out=ps_submit('newtopic',array('calendar_time'=>$values[0],'calendar_duration'=>$values[1]));
        ae_check($out==='error'&&$ps_exception===$lang['Calendar_input_error']&&ps_snapshot()===$before,'Invalid calendar input must refuse the whole new topic before partial content/index/counters');$calendar_cases++;
        fe_fixture();$before=fe_snapshot();$out=fe_submit(array('calendar'=>$values[0],'calendar_duration'=>$values[1]));
        ae_check($out==='error'&&$fe_exception===$lang['Calendar_input_error']&&fe_snapshot()===$before,'Invalid event input must roll back every full-editor participant');$calendar_cases++;
    }
    fe_fixture();ae_sql('UPDATE fixture_forums SET auth_cal=5');ae_sql('UPDATE fixture_topics SET topic_calendar_time=1234567890,topic_calendar_duration=3599');
    $out=fe_submit(array('calendar'=>array('invalid'),'calendar_duration'=>-1));$row=ae_rows('SELECT topic_calendar_time,topic_calendar_duration FROM fixture_topics WHERE topic_id=100')[0];
    ae_check(is_array($out)&&(int)$row['topic_calendar_time']===1234567890&&(int)$row['topic_calendar_duration']===3599,'Unavailable event controls must preserve saved event even with malformed unrelated input');$calendar_cases++;
    fe_fixture();$before=fe_snapshot();$out=fe_submit(array('post'=>20,'calendar'=>array('invalid'),'calendar_duration'=>-1));$after=fe_snapshot();
    ae_check(is_array($out)&&$before['topics']===$after['topics'],'Reply edits cannot change or be blocked by first-topic calendar fields');$calendar_cases++;
    ps_fixture();ae_sql('UPDATE fixture_forums SET auth_cal=5');$before=ps_snapshot();
    ae_check(ps_submit('newtopic',array('calendar_time'=>1234567890,'calendar_duration'=>3599))==='error'&&ps_snapshot()===$before,'Validated fields must not grant calendar permission');$calendar_cases++;
}
echo 'Native calendar posting: ',$calendar_cases," create/edit/capacity/authority/rollback cases PASS; canonical MariaDB/InnoDB and EN/DE.\n";
