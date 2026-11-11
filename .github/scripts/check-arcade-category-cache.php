<?php
// Real class and cache files, synthetic SELECT responses only.
require __DIR__.'/arcade-summary-fixture.php';
class ArcadeCategoryFixtureDatabase
{
    var $rows=array();var $users=array();var $queries=array();var $opened=0;var $fail='';var $bad_rows=false;
    function sql_query($sql)
    {
        $this->queries[]=$sql;
        if(strpos($sql,'SELECT c.*, g.game_name, g.game_id, g.game_desc')===0){
            as_check(strpos($sql,'JOIN fixture_users')===false,'Cached category SELECT excludes account fields');
            $kind='categories';$rows=$this->rows;
        }elseif(preg_match('/^SELECT user_id, username, user_allow_viewonline FROM fixture_users WHERE user_id IN \(([0-9,]+)\)$/D',$sql,$m)){
            $kind='users';$ids=explode(',',$m[1]);as_check(count($ids)<=500&&count(array_unique($ids))===count($ids),'Account reads are bounded and deduplicated');$rows=array();
            foreach($ids as$id){as_check((int)$id>0&&(int)$id<=8388607,'Only positive canonical account IDs reach SQL');if(isset($this->users[$id])){$rows[]=$this->users[$id];}}
        }else{throw new RuntimeException('Only expected synthetic category/account SELECTs permitted');}
        if($this->fail===$kind){return false;}$this->opened++;return(object)array('kind'=>$kind,'rows'=>$rows,'offset'=>0);
    }
    function sql_fetchrowset($r){return $this->bad_rows?false:$r->rows;}
    function sql_fetchrow($r){return isset($r->rows[$r->offset])?$r->rows[$r->offset++]:false;}
    function sql_freeresult($r){$this->opened--;}
}
function ac_row($id,$player){return array('cat_id'=>(string)$id,'last_player'=>$player,'cat_name'=>'Raw &amp; <category>','cat_desc'=>'Literal &#39;','game_name'=>'fixture_game','game_id'=>'9','game_desc'=>'Raw &amp; <game>','cat_order'=>'2','total_played'=>'1234567890123','last_game'=>'fixture_game','last_time'=>'100','last_game_cat_id'=>'1','last_game_avail'=>'1','last_game_rank'=>'0','last_game_level'=>'0','last_game_group'=>'0','last_game_cat_group'=>'0','last_game_parent_group'=>'0','last_game_category_valid'=>'1');}
function ac_clean($rows){foreach($rows as$key=>$row){unset($rows[$key]['username'],$rows[$key]['user_allow_viewonline']);}return $rows;}
function ac_payload($expected){$expected=array_map('phpbb_arcade_last_play_projection',$expected);$p=as_payload('categories');as_check(isset($p['data'])&&$p['data']===array('version'=>2,'categories'=>$expected),'Actual cache contains only scope-validated category/game metadata');}
function ac_result($actual,$expected,$users){$expected=array_map('phpbb_arcade_last_play_projection',$expected);as_check(ac_clean($actual)===$expected,'Identity refresh preserves category ordering, keys and scope-validated raw metadata');foreach($actual as$row){$id=(int)$row['last_player'];as_check($row['username']===(isset($users[$id])?$users[$id]['username']:null),'Current account name, never cached identity');as_check($row['user_allow_viewonline']===(isset($users[$id])?$users[$id]['user_allow_viewonline']:0),'Current visibility or fail-closed missing account');}as_check($GLOBALS['db']->opened===0,'Every SELECT result is released');}
function ac_visible($row){
    global $as_app,$as_cases,$lang,$userdata;$source=file_get_contents($as_app.'activity.php');
    as_check(preg_match_all('/if\(\$catrows\[(?:\$i|0)\]\[\'user_allow_viewonline\'\] \|\| \$userdata\[\'user_level\'\] == ADMIN \|\| \$userdata\[\'user_level\'\] == MOD\)\s*\{.*?\}\s*else\s*\{.*?\}/s',$source,$parts)===3,'Actual category, subcategory and all-games visibility branches');
    foreach(array(0,ADMIN,MOD)as$level){$userdata=array('user_level'=>$level);$i=0;$catrows=array($row);$temp_url='profile.php?mode=viewprofile&amp;u=7';foreach($parts[0]as$fragment){eval($fragment);$expected=$row['user_allow_viewonline']||$level===ADMIN||$level===MOD?html_entity_decode($row['username'],ENT_QUOTES,'UTF-8'):$lang['game_hidden'];as_check(html_entity_decode(strip_tags($profile),ENT_QUOTES,'UTF-8')===$expected,'Actual category renderers honor fresh visibility immediately');$as_cases++;}}
}
$db=new ArcadeCategoryFixtureDatabase();$admin_created=false;
try{
    as_open();as_language('english');$arcade->arcade_config['categories_cache']=300;
    $db->rows=array(ac_row(0,null),ac_row(1,'7'),ac_row(2,'7'),ac_row(3,'-1'),ac_row(4,'0'),ac_row(5,'8388607'));
    foreach(array('A&B',"O'Reilly",'&#39;','Grüße 😀')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){
        $arcade->clear_cache('categories','./');$key=phpbb_username_key($raw,$flags);$db->users=array(7=>array('user_id'=>'7','username'=>$key,'user_allow_viewonline'=>'1'),8388607=>array('user_id'=>'8388607','username'=>'Last account','user_allow_viewonline'=>'0'));
        $before=serialize(array($db->rows,$db->users));$n=count($db->queries);$out=$arcade->read_cat('./');ac_result($out,$db->rows,$db->users);ac_payload($db->rows);as_check(count($db->queries)===$n+2,'Cache miss uses metadata and one bulk account query');as_check(serialize(array($db->rows,$db->users))===$before,'Reading cannot change source rows');ac_visible($out[1]);$as_cases++;
        $db->users[7]['username']=phpbb_username_key('Renamed & Member');$db->users[7]['user_allow_viewonline']='0';$n=count($db->queries);$out=$arcade->read_cat('./');ac_result($out,$db->rows,$db->users);ac_payload($db->rows);as_check(count($db->queries)===$n+1,'Cache hit refreshes accounts without reloading metadata');ac_visible($out[1]);$as_cases++;
        unset($db->users[7]);$out=$arcade->read_cat('./');ac_result($out,$db->rows,$db->users);ac_payload($db->rows);$as_cases++;
    }}
    $bad=array('old rendered cache',array(ac_row(1,7)+array('username'=>'Old name','user_allow_viewonline'=>1)),array('version'=>3,'categories'=>$db->rows),array('version'=>'2','categories'=>$db->rows),array('version'=>2,'categories'=>array(false)));
    foreach(array(array(),8388608,-8388609,'7 OR 1=1',true)as$id){$bad[]=array('version'=>2,'categories'=>array(ac_row(1,$id)));}
    $bad[]=array('version'=>2,'categories'=>array(ac_row(1,7)+array('username'=>null)));$bad[]=array('version'=>2,'categories'=>array(ac_row(1,7)+array('user_allow_viewonline'=>0)));
    foreach($bad as$payload){as_check($arcade->write_cache('categories',$payload,'./'),'Own legacy/malformed fixture cache');$n=count($db->queries);$out=$arcade->read_cat('./');ac_result($out,$db->rows,$db->users);ac_payload($db->rows);as_check(count($db->queries)===$n+2,'Legacy/malformed caches rebuild from real metadata SELECT');$as_cases++;}
    $db->rows=array();$arcade->clear_cache('categories','./');as_check($arcade->read_cat('./')===array(),'An empty installation has no fabricated categories');$n=count($db->queries);as_check($arcade->read_cat('./')===array()&&count($db->queries)===$n,'Empty metadata is cached without any account query');$as_cases++;
    $db->rows=array(ac_row(0,null),ac_row(1,-1),ac_row(2,0));$arcade->clear_cache('categories','./');$n=count($db->queries);ac_result($arcade->read_cat('./'),$db->rows,array());as_check(count($db->queries)===$n+1,'Guest-only categories require no account query');$as_cases++;
    $db->rows=array();for($i=1;$i<=1001;$i++){$db->rows[]=ac_row($i,$i);}$db->users=array();$arcade->clear_cache('categories','./');$n=count($db->queries);ac_result($arcade->read_cat('./'),$db->rows,array());as_check(count($db->queries)===$n+4,'Large lists use three bounded account batches');$as_cases++;
    $db->rows=array(ac_row(1,7));$db->users=array(7=>array('user_id'=>'7','username'=>'Current','user_allow_viewonline'=>'1'));$arcade->clear_cache('categories','./');$arcade->read_cat('./');
    $p=as_payload('categories');$p['cached']=time()-1000;as_check(phpbb_data_cache_write($as_root.'/cache/arcade_categories.cache',$p),'Own expired cache');$n=count($db->queries);ac_result($arcade->read_cat('./'),$db->rows,$db->users);as_check(count($db->queries)===$n+2,'Configured TTL still reloads expired metadata');$as_cases++;
    as_check(mkdir($as_root.'/admin',0700),'Own ACP directory');$admin_created=true;as_check(chdir($as_root.'/admin'),'Enter own ACP path');ac_result($arcade->read_cat('../'),$db->rows,$db->users);as_check(chdir($as_root),'Restore own cache root');as_check(rmdir($as_root.'/admin'),'Remove own empty ACP directory');$admin_created=false;$as_cases++;
    $arcade->clear_cache('categories','./');$arcade->arcade_config['use_cache']=0;for($i=0;$i<2;$i++){$n=count($db->queries);ac_result($arcade->read_cat('./'),$db->rows,$db->users);as_check(count($db->queries)===$n+2&&!file_exists($as_root.'/cache/arcade_categories.cache'),'Disabled cache reads current metadata/accounts and writes no cache');$as_cases++;}$arcade->arcade_config['use_cache']=1;
    foreach(array('english','german')as$locale){as_language($locale);foreach(array('categories','users','bad_rows')as$failure){$arcade->clear_cache('categories','./');$db->fail=$failure==='bad_rows'?'':$failure;$db->bad_rows=$failure==='bad_rows';$caught=false;try{$arcade->read_cat('./');}catch(ArcadeSummaryExit$e){$caught=$e->getMessage()===$lang['no_cat_data'];}as_check($caught&&$db->opened===0,'Failed reads return a controlled localized error and release results');$db->fail='';$db->bad_rows=false;$as_cases++;}}
    as_check(file_get_contents($as_root.'/cache/unrelated.keep')==='Owned cache canary','Unrelated caches remain untouched');
    echo 'Arcade category cache: ',$as_cases,' cases, ',$as_checks," assertions PASS; real cache methods, fresh account names/visibility, legacy upgrade, deleted users, bounded reads, raw metadata, TTL, ACP paths and controlled failures; synthetic SELECTs only.\n";
}finally{
    if($as_created){chdir($as_root);$arcade->clear_cache('categories','./');if($admin_created){rmdir($as_root.'/admin');}}as_close();restore_error_handler();
}
