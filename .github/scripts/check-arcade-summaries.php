<?php
require __DIR__.'/arcade-summary-fixture.php';
class ArcadeSummaryReadOnly
{
	var $name='';var $winner=7;var $missing=false;var $score_missing=false;var $fail='';var $queries=array();var $opened=0;var $score='1234.5000';var $game='Literal &amp; <caption>';
	function sql_query($sql)
	{
		$this->queries[]=$sql;
		if(strpos($sql,'SELECT user_id, username FROM fixture_users WHERE user_id = ')===0){$kind='identity';$row=$this->missing?false:array('user_id'=>$this->winner,'username'=>$this->name);}
		elseif(strpos($sql,'SELECT u.user_id, u.username, a.')===0&&strpos($sql,'fixture_ina_user_data')!==false){$kind='leader';$row=$this->missing?false:array('user_id'=>$this->winner,'username'=>$this->name,'first_places'=>4,'at_first_places'=>3);}
		elseif(strpos($sql,'SELECT s.score, u.username, g.game_desc FROM fixture_ina_scores')===0){$kind='score';$row=$this->score_missing?false:array('username'=>$this->name,'score'=>$this->score,'game_desc'=>$this->game);}
		else{throw new RuntimeException('Only the actual synthetic summary SELECTs are permitted');}
		if($this->fail===$kind){return false;}$this->opened++;return(object)array('row'=>$row);
	}
	function sql_fetchrow($result){return$result->row;}function sql_freeresult($result){$this->opened--;}
}
$db=new ArcadeSummaryReadOnly();
try{as_open();
	foreach(array('english','german')as$locale){as_language($locale);
		foreach(array('A&B',"O'Reilly",'&#39;','<b>ü</b>','Grüße 😀')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){$db->name=phpbb_username_key($raw,$flags);as_check($db->name!=='','Actual stored account key');
			foreach(array('first_places'=>array('best_player',4),'at_first_places'=>array('best_at_player',1))as$type=>$setting){as_clear();$SID='sid=visitor_one';$out=best_player($type);as_check(strpos($out,htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))!==false,'Complete escaped visible stored name');as_links($out,7,'visitor_one',$setting[1]);$cache=as_payload($setting[0]);as_check($cache['data']===array('version'=>1,'type'=>$type,'user_id'=>7,'wins'=>$setting[1]===4?4:3),'Cache contains data without names, HTML, locale or SID');
				$before=count($db->queries);$SID='sid=visitor_two';$hit=best_player($type);as_check(count($db->queries)===$before+1&&strpos(end($db->queries),'SELECT user_id, username')===0,'A hit uses only a current identity lookup');as_links($hit,7,'visitor_two',$setting[1]);$as_cases++;
			}
			$latest=last_played_score();as_check(strpos($latest,htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))!==false&&strpos($latest,'1,234.5')!==false,'Latest score retains exact name and actual score formatter');as_check(strpos($latest,'Literal &amp;amp; &lt;caption&gt;')!==false,'Raw game metadata is escaped without account-key decoding');$as_cases++;
		}}
	}
	as_language('english');as_clear();$db->name=phpbb_username_key('Old & Name');$SID='sid=first';best_player();$db->name=phpbb_username_key('Renamed & Name');as_language('german');$SID='sid=second';$out=best_player();as_check(strpos($out,'Bester Spieler')!==false&&strpos($out,'Renamed &amp; Name')!==false&&strpos($out,'Old')===false&&strpos($out,'sid=first')===false,'Cache hit follows current identity, language and session');$as_cases++;
	foreach(array('Old rendered HTML sid=old',null,array(),array('version'=>0,'type'=>'first_places','user_id'=>7,'wins'=>4),array('version'=>1,'type'=>'at_first_places','user_id'=>7,'wins'=>4),array('version'=>1,'type'=>'first_places','user_id'=>'7 OR 1','wins'=>4),array('version'=>1,'type'=>'first_places','user_id'=>7,'wins'=>array(4)),array('version'=>1,'type'=>'first_places','user_id'=>7,'wins'=>8388608))as$bad){as_clear();$arcade->write_cache('best_player',$bad);$before=count($db->queries);$out=best_player();as_check(count($db->queries)===$before+1&&strpos(end($db->queries),'SELECT u.user_id')===0&&is_string($out)&&$out!=='','Legacy or malformed cache is rebuilt before rendering');$as_cases++;}
	foreach(array('identity','leader')as$failure){as_clear();$db->name='Member';if($failure==='identity'){best_player();}$db->fail=$failure;$denied=false;try{best_player();}catch(ArcadeSummaryExit$e){$denied=$e->getMessage()===$lang['no_user_data'];}as_check($denied&&$db->opened===0,'Current localized leaderboard error, no leaked result resources');$db->fail='';$as_cases++;}
	as_clear();$db->missing=true;as_check(best_player()==='','An empty leaderboard has no invented winner');$db->missing=false;$as_cases++;
	$db->fail='score';as_check(last_played_score()==='','Failed optional latest-score lookup has no warning, SQL or broken placeholder');$db->fail='';$db->score_missing=true;as_check(last_played_score()==='','Empty score history has no invented result');$db->score_missing=false;$as_cases+=2;
	as_clear();$db->queries=array();$arcade->arcade_config['use_cache']=0;best_player();best_player();as_check(count($db->queries)===2&&strpos($db->queries[1],'SELECT u.user_id')===0,'Disabled cache retains fresh leaderboard reads');$arcade->arcade_config['use_cache']=1;$as_cases++;
	as_clear();best_player();$cache=as_payload('best_player');$cache['cached']=time()-400;phpbb_data_cache_write($as_root.'/cache/arcade_best_player.cache',$cache);$before=count($db->queries);best_player();as_check(count($db->queries)===$before+1&&strpos(end($db->queries),'SELECT u.user_id')===0,'Expired data cache respects existing TTL');$as_cases++;
	as_clear();best_player();best_player('at_first_places');$arcade->clear_cache('best_player','./');as_check(!file_exists($as_root.'/cache/arcade_best_player.cache')&&file_exists($as_root.'/cache/arcade_best_at_player.cache')&&file_get_contents($as_root.'/cache/unrelated.keep')==='Owned cache canary','Established cache keys clear independently and leave other files alone');$as_cases++;
	as_check($db->opened===0,'Every successful summary result is released');
	echo 'Arcade summaries: ',$as_cases,' cases, ',$as_checks," assertions PASS; actual functions/cache files, whole account names, per-request language/SID, legacy upgrade, current identity and controlled read failures; synthetic SELECTs only.\n";
}finally{as_close();restore_error_handler();}
