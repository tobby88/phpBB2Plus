<?php
// COM_QUIT/socket close need not disappear from PROCESSLIST synchronously.
// Wait only for the fixture's own IDs; a retained connection still fails.
function native_connections_released($read_rows,$ids)
{
 $owned=array();foreach($ids as$id){if(!is_int($id)||$id<1){throw new RuntimeException('Only owned native connection IDs may be observed');}$owned[]=$id;}
 if(!$owned){return true;}gc_collect_cycles();$deadline=microtime(true)+0.5;
 do{if(!call_user_func($read_rows,'SELECT ID FROM information_schema.PROCESSLIST WHERE ID IN ('.implode(',',$owned).')')){return true;}if(microtime(true)>=$deadline){return false;}usleep(10000);}while(true);
}
