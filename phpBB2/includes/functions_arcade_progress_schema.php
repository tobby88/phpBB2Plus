<?php
// Shared pure shape contract; no database, bootstrap or automatic writes.
if(PHP_SAPI!=='cli'&&!defined('IN_PHPBB')){http_response_code(404);exit;}
function phpbb_arcade_progress_schema_matches($columns, $indexes)
{
    $actual = array();
    foreach ($columns as $column) {
        $type = preg_replace('/^(mediumint|bigint)\([0-9]+\)/', '$1', strtolower($column['Type']));
        $actual[$column['Field']] = array($type, $column['Null'], $column['Default'], $column['Collation'], $column['Extra']);
    }
    $expected = array('user_id'=>array('mediumint unsigned','NO',null,null,''),
        'game_id'=>array('mediumint','NO',null,null,''), 'game_data'=>array('text','NO',null,'utf8mb4_unicode_ci',''),
        'saved_at'=>array('bigint unsigned','NO','0',null,''));
    if ($actual !== $expected) { return false; }
    $keys = array();
    foreach ($indexes as $index) {
        if ((isset($index['Ignored']) && $index['Ignored'] !== 'NO') || (isset($index['Visible']) && $index['Visible'] !== 'YES')) { return false; }
        $keys[$index['Key_name']][(int)$index['Seq_in_index']] = array($index['Column_name'], (int)$index['Non_unique'], $index['Sub_part'], $index['Index_type']);
    }
    ksort($keys, SORT_STRING);
    return $keys === array('PRIMARY'=>array(1=>array('user_id',0,null,'BTREE'),2=>array('game_id',0,null,'BTREE')),
        'game_id'=>array(1=>array('game_id',1,null,'BTREE')));
}
