<?php
require __DIR__.'/arcade-game-fixture.php';
$count=0;$valid=ag_values();$clean=phpbb_arcade_game_values($valid);
as_check(array_keys($clean)===phpbb_arcade_game_fields(),'Exact writable fields');$count++;
foreach(array('0',"O'Reilly",'ä😀game','raw&amp;game',str_repeat('x',50))as$name){$v=$valid;$v['game_name']=$name;as_check(phpbb_arcade_game_values($v)['game_name']===$name,'Exact raw game identifier');$count++;}
$invalid=array(array('game_name',''),array('game_name',str_repeat('x',51)),array('game_name','../game'),array('game_name',' game'),array('game_name','a b'),array('game_name',"a\0b"),array('game_name',"bad\xff"),array('game_path','https://example.org/'),array('game_path','../games/'),array('game_path','games'),array('image_path','../image.png'),array('cat_id',0),array('cat_id',8388608),array('cat_id',array(7)));
foreach(array('game_charge'=>'4294967296','game_reward'=>'4294967296','game_bonus'=>65536,'at_game_bonus'=>32768,'win_width'=>4097,'win_height'=>4097,'at_highscore_limit'=>32768,'highscore_limit'=>'9223372036854775808','group_required'=>8388608,'rank_required'=>'2147483648','level_required'=>128,'game_control'=>4,'score_type'=>9,'game_autosize'=>2)as$key=>$value){$invalid[]=array($key,$value);}
foreach(array('game_use_gl','game_flash','game_show_score','game_avail','allow_guest','reverse_list')as$key){$invalid[]=array($key,2);}
foreach($invalid as$case){$v=$valid;$v[$case[0]]=$case[1];$caught=false;try{phpbb_arcade_game_values($v);}catch(PhpbbOwnedStorageFailure$e){$caught=true;}as_check($caught,'Refuse malformed field '.$case[0]);$count++;}
foreach(array('extra','missing','wrong-type','negative','leading-zero')as$case){$v=$valid;if($case==='extra'){$v['injected']=1;}elseif($case==='missing'){unset($v['game_avail']);}else{$v['game_charge']=$case==='wrong-type'?array(1):($case==='negative'?-1:'01');}$caught=false;try{phpbb_arcade_game_values($v);}catch(PhpbbOwnedStorageFailure$e){$caught=true;}as_check($caught,'Prepared capability shape/type '.$case);$count++;}
foreach(array('game_desc'=>str_repeat('ä😀',127).'ä','image_path'=>str_repeat('ä',250).'.png','instructions'=>str_repeat('😀',16000))as$key=>$value){$v=$valid;$v[$key]=$value;as_check(phpbb_arcade_game_values($v)[$key]===$value,'Raw multibyte capacity '.$key);$count++;}
$row=array_merge(array('game_id'=>41,'played'=>12,'highscore_id'=>8,'at_highscore_id'=>8,'date_added'=>1),$valid);$revision=phpbb_arcade_game_revision($row);
as_check(preg_match('/^[a-f0-9]{64}$/D',$revision)===1,'Stable revision shape');$count++;
foreach(array_merge(array('game_id'),phpbb_arcade_game_fields())as$key){$r=$row;$r[$key]=(string)$r[$key].'x';as_check(phpbb_arcade_game_revision($r)!==$revision,'Every editable field pinned '.$key);$count++;}
foreach(array('played','highscore_id','at_highscore_id','date_added')as$key){$r=$row;$r[$key]=999;as_check(phpbb_arcade_game_revision($r)===$revision,'Routine gameplay does not stale edit '.$key);$count++;}
$r=$row;unset($r['game_id']);$caught=false;try{phpbb_arcade_game_revision($r);}catch(PhpbbOwnedStorageFailure$e){$caught=true;}as_check($caught,'Incomplete revision fails');$count++;
foreach(array('english','german')as$locale){ag_language($locale);as_check(isset($lang['arcade_game_changed'])&&preg_match('//u',$lang['arcade_game_changed']),'Localized stale form result');$count++;}
$source=file_get_contents($as_app.'admin/admin_arcade_games.php');as_check(strpos($source,'arcade_admin_rename_game_references')===false,'No independently committed rename helper');as_check(strpos($source,'name="game_revision"')!==false,'Actual form carries the revision');
echo 'Arcade manual game inputs: '.$count.' cases, '.$as_checks." assertions PASS.\n";
