<?php
require __DIR__.'/arcade-import-fixture.php';$count=0;
foreach(array('fixture','0',str_repeat('x',50),str_repeat('😀',12),"a'b",'a&amp;b')as$name){$v=aif_values($name);as_check($v!==false&&$v['game_name']===$name,'Exact valid protocol identity');$count++;}
foreach(array('', ' ', ' leading', 'trailing ','.','..','with space','../game','a/b','a\\b',"bad\0name","bad\xff",str_repeat('x',51),str_repeat('😀',13),array())as$name){as_check(aif_values($name)===false,'Invalid/overlong identity never becomes another asset');$count++;}
foreach(array('games/',"games/O'Reilly/",'games/ä😀/','games/a&amp;b/')as$path){$v=phpbb_arcade_import_values('fixture',$path,0,'',1,1,0,0,0);as_check($v!==false&&$v['game_path']===$path&&$v['cat_id']===-1&&$v['win_width']===550&&$v['win_height']===450,'Exact raw local path and defaults');$count++;}
foreach(array('', '../games/', '/absolute/', 'https://example.test/',"bad\0path","bad\xff",str_repeat('x',256),array())as$path){as_check(phpbb_arcade_import_values('fixture',$path,0,'',1,1,0,0,7)===false,'Invalid local path refused without clipping');$count++;}
foreach(array('', '0',"Raw \\ &amp; '&#39;'",str_repeat('😀',255).'Z')as$description){$v=aif_values('test_game',7,1,$description);$expected=$description===''?'Test game':($description===str_repeat('😀',255).'Z'?str_repeat('😀',255):$description);as_check($v!==false&&$v['game_desc']===$expected,'Raw captions/default derivation and Unicode character limits');$count++;}
foreach(array("bad\xff","bad\0caption",array())as$bad){as_check(aif_values('fixture',7,1,$bad)===false,'Invalid captions refused');$count++;}
foreach(array(-2,8388608,'1 OR 1',array())as$bad){as_check(aif_values('fixture',$bad)===false,'Invalid category shape/range');$count++;}
$v=phpbb_arcade_import_values('fixture','games/',1,'Caption',0,0,100000,99999,-1);as_check($v['win_width']===4096&&$v['win_height']===4096&&$v['reverse_list']===1&&$v['game_flash']===0&&$v['game_avail']===0,'Dimensions and flags canonical');$count++;
$v=phpbb_arcade_import_values('fixture','games',0,'Caption',1,1,0,0,7);as_check($v['game_path']==='games/','Canonical directory separator retained');$count++;
$routes=aif_routes();as_check(count(array_unique($routes))===3,'Import/Add/Edit remain distinct actual grants');$count++;
$source=file_get_contents($as_app.'admin/admin_arcade_games.php');as_check(preg_match_all('/if \(insert_ina_game\(([^;\r\n]+)\)\) \{ \$i\+\+; \} else \{ \$skipped\+\+; \}/',$source,$m)===4,'All four controller calls count only committed positive IDs');$count++;
as_check(preg_match('/\$import_path = phpbb_arcade_local_asset\(arcade_admin_post_string\(\x27import_path\x27\)\);/',$source)===1,'Actual import path controller');
foreach(array("games/O'Reilly/",'games/ä😀/','games/a&amp;b/')as$raw){profile_fixture_request(array('import_path'=>$raw));$value=phpbb_arcade_local_asset(arcade_admin_post_string('import_path'));as_check($value===$raw,'Actual request escaping removed once for import path');$count++;}
echo 'Arcade import inputs: '.$count." raw metadata, exact identifiers, controller accounting and module-route cases PASS.\n";
