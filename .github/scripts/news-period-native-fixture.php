<?php
// Reuse only the owned server/schema setup, actual driver and actual forum ACLs.
function np_events(){
 $dates=array(10=>'1970-01-01 00:00:00',11=>'1969-12-31 23:59:59',20=>'2023-12-31 23:59:59',21=>'2024-01-01 00:00:00',22=>'2024-01-01 00:00:01',23=>'2024-01-31 23:59:59',24=>'2024-02-01 00:00:00',25=>'2024-02-28 23:59:59',26=>'2024-02-29 00:00:00',27=>'2024-02-29 23:59:59',28=>'2024-03-01 00:00:00',29=>'2024-12-31 23:59:59',30=>'2025-01-01 00:00:00',31=>'2038-01-19 03:14:07',40=>'2024-02-29 00:00:00',41=>'2024-02-29 00:00:00',42=>'2024-02-29 00:00:00',43=>'2024-02-29 00:00:00',44=>'2024-02-29 00:00:00',45=>'2024-02-29 00:00:00',50=>'2024-03-31 00:00:00',51=>'2024-03-31 23:59:59',52=>'2024-04-01 00:00:00',53=>'2024-10-27 00:00:00',54=>'2024-10-27 23:59:59',55=>'2024-10-28 00:00:00',56=>'2024-11-01 00:00:00',57=>'2024-10-31 12:00:00',60=>'1968-12-31 23:59:59',61=>'1967-12-31 23:59:59');
 $events=array();foreach($dates as$id=>$date){$events[]=array('id'=>$id,'date'=>$date,'forum'=>in_array($id,array(40,60),true)?9:(in_array($id,array(41,61),true)?4:3),'news'=>$id===42?0:($id===43?777:($id===45?2:1)),'deleted'=>$id===44?1:0);}return$events;
}
function np_visible($events,$role){$out=array();foreach($events as$event){if(!in_array($event['news'],array(1,2),true)||($role!=='admin'&&$event['forum']!==3)){continue;}$out[]=$event;}return$out;}
function np_day_ids($events,$date){$out=array();foreach($events as$event){if(substr($event['date'],0,10)===$date){$out[]=$event['id'];}}sort($out);return$out;}
function np_counts($events,$prefix,$month){$out=array_fill(1,$month?31:12,0);foreach($events as$event){if(substr($event['date'],0,strlen($prefix))===$prefix){$out[(int)substr($event['date'],$month?8:5,2)]++;}}return$out;}
function np_period_render($data,$file,$events,$day=false){
 global$template,$nt_checks;
 $template=nt_template($file);$n=nt_module($template);$n->data=$data;$n->renderArchives(2024,2,$day?29:0);$html=nt_html($template);
 nt_check(strpos($html,'year=2024&amp;month=2&amp;day=29')!==false,'Actual archive template emits the leap-day link');
 if($day){nt_check(count($template->_tpldata['articles.'])===count(np_day_ids($events,'2024-02-29')),'Actual day archive renders every permitted row including deleted position');nt_check(substr_count($html,$GLOBALS['lang']['Post_deleted_placeholder'])===1,'Deleted first-post marker remains compact in the day archive');}
 else{nt_check(strpos($html,'29th ('.np_counts($events,'2024-02',true)[29].')')!==false,'Actual month archive shows the exact permitted day count');}
 nt_check(preg_match('//u',$html)===1&&strpos($html,'MUST_NOT_LEAK')===false,'Actual News/portal archive has safe UTF-8 output');
}
function nn_period_suite(){
 global$db,$userdata,$tree,$nt_app,$cases,$nt_checks;
 $events=np_events();nn_sql('START TRANSACTION');
 try{
  nn_sql('INSERT INTO fixture_forums(forum_id,cat_id,auth_view,auth_read) VALUES(4,1,0,5)');nn_sql("INSERT INTO fixture_news(news_id,news_category,news_image) VALUES(2,'Second category','general.gif')");
  foreach($events as$event){$id=$event['id'];$forum=$event['forum'];$news=$event['news'];$deleted=$event['deleted'];$clock=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$event['date'],new DateTimeZone('UTC'));$epoch=$clock->format('U');$author=$deleted?-1:7;
   nn_sql("INSERT INTO fixture_topics(topic_id,forum_id,topic_first_post_id,topic_last_post_id,topic_poster,topic_title,topic_time,news_id) VALUES($id,$forum,$id,$id,$author,'Archive $id',$epoch,$news)");nn_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_deleted) VALUES($id,$id,$forum,$author,$epoch,'7f000001',$deleted)");nn_sql("INSERT INTO fixture_posts_text(post_id,bbcode_uid,post_subject,post_text) VALUES($id,'','Subject $id','".($deleted?'OLD_BODY_MUST_NOT_LEAK':"Visible body $id")."')");
  }nn_sql('COMMIT');
 }catch(Exception$error){nn_sql('ROLLBACK');throw$error;}
 $db->reading=true;$before=nn_snapshot();nt_context('english',false);$userdata['user_level']=USER;$tree=array();$data=new NewsDataAccess($nt_app);$data->setItemCount(100);$visible=np_visible($events,'guest');
 foreach(array('1970-01-01','2024-01-01','2024-02-01','2024-02-29','2024-03-01','2024-03-31','2024-04-01','2024-10-27','2024-10-28','2024-11-01','2025-01-01','2038-01-19','2069-01-01')as$date){$rows=$data->fetchDay(substr($date,8,2),substr($date,5,2),substr($date,0,4));nt_check(nn_ids($rows,'topic_id')===np_day_ids($visible,$date),'Exact native UTC day has inclusive lower/exclusive upper boundary');$cases++;}
 foreach(array('2024-01','2024-02','2024-03','2024-04','2024-10','2024-11','2024-12')as$month){nt_check($data->fetchDays(substr($month,5,2),substr($month,0,4))===np_counts($visible,$month,true),'Exact native month retains first midnight and correct days');$cases++;}
 foreach(array('1970','2024','2025','2038','2069')as$year){nt_check($data->fetchMonths($year)===np_counts($visible,$year,false),'Exact native year retains January midnight and complete months');$cases++;}
 foreach(array('english','german')as$locale){foreach(array('guest','member','admin')as$role){foreach(array('UTC','Europe/Berlin','Pacific/Kiritimati','America/New_York')as$zone){date_default_timezone_set($zone);nt_context($locale,$role!=='guest');$userdata['user_id']=$role==='guest'?-1:($role==='admin'?9:8);$userdata['user_level']=$role==='admin'?ADMIN:USER;$tree=array();$visible=np_visible($events,$role);$data=new NewsDataAccess($nt_app);$data->setItemCount(100);
  nt_check(nn_ids($data->fetchDay(29,2,2024),'topic_id')===np_day_ids($visible,'2024-02-29'),'Actual day reader keeps current view/read ACLs in every server zone');$cases++;
  nt_check($data->fetchDays(2,2024)===np_counts($visible,'2024-02',true),'Actual day grouping remains UTC and ACL-scoped');$cases++;
  nt_check($data->fetchMonths(2024)===np_counts($visible,'2024',false),'Actual month grouping remains UTC and ACL-scoped');$cases++;
  $dates=array();foreach($visible as$event){$dates[]=substr($event['date'],0,4);}nt_check($data->fetchYears()===array('min'=>(int)min($dates),'max'=>(int)max($dates)),'Actual year range keeps ACLs and no server-DST drift');$cases++;
  foreach(array('news.tpl','portal_body.tpl')as$file){np_period_render($data,$file,$visible,false);np_period_render($data,$file,$visible,true);$cases+=2;}
  nt_check(nn_snapshot()===$before,'Every archive reader/template leaves canonical data unchanged');
 }}}
 date_default_timezone_set('UTC');
 foreach(array(array(0,2,2024),array(30,2,2024),array(1,13,2024),array(1,1,2070),array(array(1),1,2024))as$invalid){$count=$db->num_queries;nt_check($data->fetchDay($invalid[0],$invalid[1],$invalid[2])===array()&&$db->num_queries===$count,'Invalid calendar date returns an empty article list without SQL');$cases++;}
 foreach(array(array(0,2024),array(13,2024),array(1,0),array(1,2070))as$invalid){$count=$db->num_queries;nt_check($data->fetchDays($invalid[0],$invalid[1])===array_fill(1,31,0)&&$db->num_queries===$count,'Invalid month returns the stable zero-count shape without SQL');$cases++;}
 $count=$db->num_queries;nt_check($data->fetchMonths(0)===array_fill(1,12,0)&&$db->num_queries===$count,'Invalid year returns stable zero-count shape without SQL');$cases++;
 foreach(array('day','days','months','years')as$kind){$db->failure=$kind==='day'?'SELECT t.topic_id, t.topic_title':($kind==='years'?'MAX( t.topic_time ) AS max_time':'SELECT t.topic_time FROM');$failed=false;try{if($kind==='day'){$data->fetchDay(29,2,2024);}elseif($kind==='days'){$data->fetchDays(2,2024);}elseif($kind==='months'){$data->fetchMonths(2024);}else{$data->fetchYears();}}catch(NewsReaderFailure$error){$failed=true;}finally{$db->failure='';}nt_check($failed,'Archive SQL failures use the controlled error boundary');$cases++;}
 nt_check(nn_snapshot()===$before,'Failure and invalid-input probes leave canonical data unchanged');
 echo 'News archive native: ',$cases,' cases, ',$nt_checks," assertions PASS; canonical InnoDB, exact UTC boundaries, epoch/leap/DST/2038/future ranges, actual ACLs, EN/DE archive templates, invalid dates, controlled failures and unchanged data.\n";
}
