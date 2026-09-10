<?php
namespace PhpbbDriverFixture;
// Execute the actual driver, substituting only the mysqli transport/result API.
// These namespaced stubs also work when the native extension is already loaded.
class mysqli { public $closed=false; public $error=''; public $errno=0; }
class mysqli_result
{
	public $rows; public $position=0; public $closed=false;
	function __construct($rows) { $this->rows=$rows; }
}
function mysqli_connect($server,$user,$password,$database,$port)
{
	$GLOBALS['driver_fixture_connect']=array($server,$user,$password,$database);
	return new mysqli();
}
function mysqli_set_charset($connection,$charset) { return $charset==='utf8mb4'; }
function mysqli_character_set_name($connection) { return 'utf8mb4'; }
function mysqli_select_db($connection,$database) { return true; }
function mysqli_query($connection,$sql)
{
	if($connection->closed) { throw new \RuntimeException('Closed connection used'); }
	if($sql==='FAIL') { $connection->error='fixture SQL failure'; $connection->errno=1064; return false; }
	$connection->error=''; $connection->errno=0;
	if(strpos($sql,'SELECT GET_LOCK(')===0) { return new mysqli_result(array(array('acquired'=>'1'))); }
	if($sql==='SELECT first') { return new mysqli_result(array(array(0=>11,1=>null,'id'=>11,'value'=>null),array(0=>12,1=>'Grüße','id'=>12,'value'=>'Grüße'))); }
	if($sql==='SELECT second') { return new mysqli_result(array(array(0=>99,1=>'other','id'=>99,'value'=>'other'))); }
	if($sql==='SELECT empty') { return new mysqli_result(array()); }
	return true;
}
function mysqli_close($connection) { if($connection->closed) { throw new \RuntimeException('Connection closed twice'); } $connection->closed=true; return true; }
function mysqli_num_fields($result) { if(!($result instanceof mysqli_result) || $result->closed) { throw new \RuntimeException('Invalid result'); } return 2; }
function mysqli_num_rows($result) { mysqli_num_fields($result); return count($result->rows); }
function mysqli_fetch_array($result) { mysqli_num_fields($result); return isset($result->rows[$result->position])?$result->rows[$result->position++]:null; }
function mysqli_fetch_field_direct($result,$offset)
{
	mysqli_num_fields($result); if(!is_int($offset) || $offset<0 || $offset>=2) { throw new \RuntimeException('Invalid field offset'); }
	return (object)array('name'=>$offset===0?'id':'value','type'=>$offset===0?3:253);
}
function mysqli_data_seek($result,$row) { mysqli_num_fields($result); if($row<0 || $row>=count($result->rows)) { throw new \RuntimeException('Invalid row'); } $result->position=$row; return true; }
function mysqli_free_result($result) { mysqli_num_fields($result); $result->closed=true; }
function mysqli_error($connection) { return $connection->error; }
function mysqli_errno($connection) { return $connection->errno; }
function mysqli_connect_error() { return ''; }
function mysqli_connect_errno() { return 0; }
function check($condition,$message) { if(!$condition) { throw new \RuntimeException($message); } }
define('BEGIN_TRANSACTION',1); define('END_TRANSACTION',2);
$source=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/db/mysqli.php');
eval('namespace PhpbbDriverFixture;'.substr($source,5));
$reflection=new \ReflectionClass(__NAMESPACE__.'\\sql_db');
function driver()
{
	$db=$GLOBALS['reflection']->newInstanceWithoutConstructor(); $db->db_connect_id=new mysqli(); return $db;
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new \RuntimeException($message); } });
try
{
	$db=driver();
	foreach(array(false,BEGIN_TRANSACTION,END_TRANSACTION) as $flag)
	{
		check($db->sql_query('FAIL',$flag)===false,'Failed SQL is never promoted to success by legacy transaction flag');
		$error=$db->sql_error(); check($error['code']===1064 && $error['message']==='fixture SQL failure','Original driver error remains available');
	}
	check($db->sql_query('',END_TRANSACTION)===true && $db->query_result===false,'Empty legacy end marker is a harmless no-op');
	check($db->sql_query('')===false && $db->sql_query('',BEGIN_TRANSACTION)===false,'Other empty queries are not successes');
	check($db->num_queries===3,'Empty queries do not count as executed SQL');
	check($db->sql_query('UPDATE fixture',END_TRANSACTION)===true,'Successful writes retain boolean success');
	check($db->sql_numrows()===false && $db->sql_numfields()===false && $db->sql_fetchrow()===false && $db->sql_fetchrowset()===false && $db->sql_freeresult()===false,'Write booleans are not result objects');
	$first=$db->sql_query('SELECT first'); $second=$db->sql_query('SELECT second');
	check($db->sql_numrows($first)===2 && $db->sql_numfields($first)===2,'Result metadata uses mysqli result, not connection-only field_count');
	check($db->sql_fieldname(1,$first)==='value' && $db->sql_fieldtype(0,$first)===3,'Field names and types remain available');
	check($db->sql_fetchfield('id',-1,$first)===11 && $db->sql_fetchfield('value',-1,$first)==='Grüße','Sequential field fetch honors explicit result rather than most recent query');
	check($db->sql_fetchfield('id',-1,$first)===false,'End of result is a defined false value');
	check($db->sql_fetchfield('value',0,$first)===null,'Indexed nullable field preserves SQL NULL');
	check($db->sql_fetchfield(0,1,$first)===12,'Indexed field fetch calls the class helper, not an undefined global mysqli_result function');
	check($db->sql_fetchfield('missing',0,$first)===false && $db->sql_fetchfield('id',99,$first)===false,'Missing fields/rows do not emit warnings');
	check($db->sql_fetchfield('id',0,$second)===99,'Interleaved explicit results remain independent');
	check($db->sql_rowseek(0,$second) && $db->sql_fetchfield('id','-1',$second)===99,'Legacy string negative cursor keeps sequential field semantics');
	check($db->sql_rowseek(0,$first)===true && count($db->sql_fetchrowset($first))===2 && $db->sql_fetchrowset($first)===array(),'Rowset consumes only its result and returns empty array at EOF');
	foreach(array(-1,2,'bad',array(0)) as $offset) { check($db->sql_fieldname($offset,$first)===false && $db->sql_fieldtype($offset,$first)===false,'Invalid field offset is controlled'); }
	foreach(array(-1,2,'bad',array(0)) as $row) { check($db->sql_rowseek($row,$first)===false,'Invalid seek is controlled'); }
	check($db->sql_freeresult($first)===true && $db->sql_freeresult($first)===false,'Repeated release is safe');
	check($db->sql_fetchrow($first)===false && $db->sql_numfields($first)===false && $db->sql_numrows($first)===false && $db->sql_fetchfield('id',0,$first)===false,'Released explicit results cannot crash consumers');
	check($db->query_result===$second && $db->sql_numrows()===1,'Releasing older result preserves current result');
	check($db->sql_fetchrow(false)===false,'Explicit failure is not silently replaced with latest successful result');
	$empty=$db->sql_query('SELECT empty'); check($db->sql_numrows()===0 && $db->sql_numfields()===2 && $db->sql_fetchrow()===false && $db->sql_fetchfield('id',0)===false,'Empty SELECT is valid metadata but has no rows');
	check($db->sql_freeresult()===true && $db->query_result===false,'Release current result clears pointer');
	check($db->sql_close()===true && $db->db_connect_id===false && $db->sql_close()===false,'Close is safe to repeat and invalidates owned connection');
	check($db->sql_query('SELECT second')===false,'Closed driver cannot execute SQL');
	// Exercise the real credential reset from the CrackerTracker bootstrap,
	// rather than a fixture that retains the original public password forever.
	$db=new sql_db('fixture','fixture-user','fixture-secret','fixture-db',false);
	$dbuser='fixture-user'; $dbpasswd='fixture-secret';
	$reset=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/ctracker/engines/ct_varsetter.php');
	$reset_start=strpos($reset,'unset($dbuser)'); $reset_end=strpos($reset,'include($phpbb_root_path',$reset_start);
	check($reset_start!==false && $reset_end>$reset_start,'Locate actual CrackerTracker variable reset');
	eval(substr($reset,$reset_start,$reset_end-$reset_start));
	check(!isset($db->password) && !isset($dbpasswd) && !isset($dbuser),'Public credentials remain removed after CrackerTracker');
	$dedicated=$db->sql_dedicated_connection();
	check($dedicated!==$db && $dedicated->db_connect_id!==$db->db_connect_id,'Writer obtains an independent connection');
	check($GLOBALS['driver_fixture_connect']===array('fixture','fixture-user','fixture-secret','fixture-db'),'Factory retains original credentials privately');
	check($dedicated->persistency===false && !isset($dedicated->password) && !isset($db->password),'Neither parent nor writer restores public password');
	ob_start(); var_dump($db); $debug=ob_get_clean();
	check(strpos($debug,'fixture-secret')===false && strpos($debug,'dedicated_connection_factory')===false,'Debug dump cannot expose captured credentials');
	$dedicated->sql_close();
	define('IN_PHPBB',true); define('ATTACHMENTS_TABLE','fixture_links');
	$mutation=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/attach_mod/includes/functions_mutation.php');
	// Eval retains the fixture namespace but must resolve the production
	// module's sibling dependencies relative to that module, not this script.
	$mutation=str_replace('dirname(__FILE__)',var_export(dirname(dirname(__DIR__)).'/phpBB2/attach_mod/includes',true),$mutation);
	eval('namespace PhpbbDriverFixture;'.substr($mutation,5));
	$lock=new attach_mutation_lock($db,false);
	check($lock->acquired && !isset($db->password) && !isset($lock->connection->password),'Actual writer lock works after real CrackerTracker reset');
	$lock->release(); $db->sql_close();
	$missing=(object)array('server'=>'fixture','user'=>'fixture','dbname'=>'fixture');
	$lock=new attach_mutation_lock($missing,false);
	check(!$lock->acquired,'Adapter without credentials fails closed without an undefined-property warning');
	echo "MySQLi failure, result metadata, field/cursor and connection contract checks passed.\n";
}
finally { restore_error_handler(); }
