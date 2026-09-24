<?php
namespace ErcPasswordProofFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require $root.'includes/php_compat.php';
define('ADMIN',1);define('USERS_TABLE','fixture_users');
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
function phpbb_password_verify($password,$hash){$GLOBALS['proof_verifications']++;return \phpbb_password_verify($password,$hash);}
$source=file_get_contents($root.'includes/functions_dbmtnc.php');$a=strpos($source,'function check_authorisation(');$b=strpos($source,'/**',$a);
check($a!==false&&$b>$a,'Actual credential verifier found');eval('namespace ErcPasswordProofFixture;'.substr($source,$a,$b-$a));
class ProofDatabase
{
    public $row,$reads=0;
    function sql_query($sql){$this->reads++;return true;}
    function sql_fetchrow($r){return $this->row;}
    function sql_freeresult($r){}
    function sql_escape($value){return addslashes($value);}
}
$db=new ProofDatabase();$option='cct';$dbuser='fixture-owner';$dbpasswd='fixture-owner-password';
$HTTP_POST_VARS=array('auth_method'=>'board','board_user'=>'Admin','board_password'=>'Fixture!9');
$db->row=array('user_id'=>2,'username'=>'Admin','user_password'=>md5('Fixture!9'),'user_active'=>1,'user_level'=>ADMIN);
$proof_verifications=0;
check(check_authorisation(false)&&$proof_verifications===1,'Initial credential really verified');
for($i=0;$i<10;$i++){check(check_authorisation(false),'Repeated valid credential');}
check($proof_verifications===1&&$db->reads===11,'Reuse only password proof; every account lookup remains fresh');
foreach(array('user_active'=>0,'user_level'=>0) as $field=>$value)
{
    $db->row[$field]=$value;check(!check_authorisation(false),'Changed account rights cannot reuse cached authorization');$db->row[$field]=1;
}
$db->row['user_password']=md5('Independent!9');
check(!check_authorisation(false)&&$proof_verifications===2,'Replacement hash invalidates proof');
$db->row['user_password']=md5('Fixture!9');$HTTP_POST_VARS['board_password']='Wrong!9';
check(!check_authorisation(false)&&!check_authorisation(false)&&$proof_verifications===4,'Failed candidate checks are not cached');
$HTTP_POST_VARS['board_password']='Fixture!9';$db->row['user_id']=8;
check(check_authorisation(false)&&$proof_verifications===5,'Rebound account must verify its own proof');
$db->row['user_password']=password_hash('Fixture!9',PASSWORD_BCRYPT);
check(check_authorisation(false)&&$proof_verifications===6,'Rehashed credential is verified before reuse');
check(check_authorisation(false)&&$proof_verifications===6,'Current adaptive hash proof reused');
$db->row['user_password']='invalid-hash';
check(!check_authorisation(false)&&!check_authorisation(false)&&$proof_verifications===8,'Invalid hashes never become successful proofs');
$HTTP_POST_VARS['board_password']=array('Fixture!9');$reads=$db->reads;
check(!check_authorisation(false)&&$proof_verifications===8&&$db->reads===$reads,'Malformed candidate does not reach proof or database');
echo "ERC password proof: exact credentials reused only within the request, with fresh account reads and uncached failures.\n";
