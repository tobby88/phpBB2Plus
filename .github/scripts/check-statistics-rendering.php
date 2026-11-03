<?php
require __DIR__.'/statistics-rendering-fixture.php';
class StatisticsRenderingResult { var $rows; var $index=0; function __construct($rows) { $this->rows=$rows; } }
class StatisticsRenderingSyntheticDatabase {
    var $name='Member'; var $attachment=''; var $fail=false; var $queries=0; var $retired=false; var $fail_current=false;
    var $current_reads=0; var $current_frees=0;
    function sql_query($sql,$transaction=false) {
        $this->queries++; if($this->fail){return false;}
        if(strpos($sql,'SELECT user_id, username FROM ')===0 && strpos($sql,'WHERE user_id IN (')!==false){
            $this->current_reads++; if($this->fail_current){return false;}
            $rows=array(array('user_id'=>6,'username'=>'Other'));if(!$this->retired){$rows[]=array('user_id'=>7,'username'=>$this->name);}
            $r=new StatisticsRenderingResult($rows);$r->index=0;return $r;
        }elseif(strpos($sql,'SELECT SUM(user_posts)')!==false){$rows=array(array('total_posts'=>4));}
        elseif(strpos($sql,'SELECT user_regdate')!==false){$rows=array(array('user_regdate'=>1));}
        elseif(strpos($sql,'FROM fixture_config')!==false){$rows=array();}
        elseif(strpos($sql,'SELECT forum_id')!==false){$rows=array_map(function($id){return array('forum_id'=>$id);},array(3,8,9,10));}
        elseif(strpos($sql,'SELECT a.post_id')!==false){
            sp_check(strpos($sql,'p.forum_id IN (3)')!==false,'Actual attachment SQL excludes denied/read-only/download-only forums');
            $rows=array(array('attach_id'=>11,'post_id'=>1,'topic_title'=>$this->attachment,'real_filename'=>$this->attachment,'comment'=>$this->attachment,'filesize'=>2048,'download_count'=>9,'filetime'=>1));
        }elseif(strpos($sql,'ORDER BY user_id DESC')!==false){$rows=array(array('user_id'=>7,'username'=>$this->name));}
        elseif(stripos($sql,'u.username')!==false || strpos($sql,'SELECT user_id, username, user_posts')!==false){$rows=array(array('user_id'=>7,'username'=>$this->name,'user_posts'=>3),array('user_id'=>6,'username'=>'Other','user_posts'=>1));}
        else{throw new RuntimeException('Unexpected synthetic statistics query');}
        return new StatisticsRenderingResult($rows);
    }
    function sql_numrows($r){return count($r->rows);}
    function sql_fetchrow($r){return isset($r->rows[$r->index])?$r->rows[$r->index++]:false;}
    function sql_fetchrowset($r){return $r->rows;}
    function sql_freeresult($r){$this->current_frees++;return true;}
}
$db=new StatisticsRenderingSyntheticDatabase(); sr_name_suite(function($key){$GLOBALS['db']->name=$key;});
sr_cache_suite(function($key){$GLOBALS['db']->name=$key;},function(){$GLOBALS['db']->retired=true;},function(){$GLOBALS['db']->retired=false;});
sp_check($db->current_reads===$db->current_frees+3,'Every successful current-account read is freed; exactly three intentional failures');
foreach(sr_excerpt_cases() as $case){$db->attachment=$case[0];sr_check_excerpt(sr_attachments(),$case);}
$invalid=array(str_repeat('a',29)."\xC3".'suffix',str_repeat('a',29)."\xEF\xBF\xBD...",true);$db->attachment=$invalid[0];sr_check_excerpt(sr_attachments(),$invalid);
$empty=sr_attachments(false);sp_check(!$empty->blocks,'No authorized forums renders no attachment data');$sr_cases++;
$db->fail=true;$caught=false;try{sr_attachments();}catch(StatisticsPeriodExit $e){$caught=true;}sp_check($caught,'Failed forum read never becomes a successful attachment listing');$sr_cases++;
echo 'Statistics rendering: ',$sr_cases,' cases, ',$sp_checks," assertions PASS; four account labels, canonical/legacy keys, raw UTF-8 excerpts/full tooltips, unchanged ranking/links, attachment ACLs and failed reads.\n";
restore_error_handler();
