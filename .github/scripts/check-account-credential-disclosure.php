<?php
namespace MailDeliveryFixture;
require __DIR__ . '/check-mail-delivery-runtime.php';
require_once $root.'includes/php_compat.php';
if(!defined('GENERAL_MESSAGE')) { define('GENERAL_MESSAGE',200); }
$admin=file_get_contents($root.'admin/admin_users.php');
foreach(array('admin_user_post_string','admin_user_require_creation_password') as $function)
{
	mail_check(preg_match('/^function '.$function.'\(.*?^\}/ms',$admin,$match)===1,'Actual administrator helper found: '.$function);
	eval('namespace MailDeliveryFixture;'.$match[0]);
}
$begin=strpos($admin,'$new_user = ((int) admin_user_post_string'); $end=strpos($admin,'//see if user already exist',$begin);
mail_check($begin!==false && $end>$begin,'Actual early new-account dispatch located'); $gate='namespace MailDeliveryFixture;'.substr($admin,$begin,$end-$begin).'}';
mail_check(strpos($gate,'admin_user_require_creation_password();')!==false && $end<strpos($admin,'$user_id = phpbb_allocate_user_id('),'Password gate precedes ID reservation and all account/group creation');
mail_check(strpos($admin,'DEFAULT_PASSWD')===false,'No predictable fallback constant, hash or explanation remains');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try
{
	$lang['New_user_password_required']='required'; $lang['Password_mismatch']='mismatch';
	foreach(array(array(),array('password'=>''),array('password'=>array('secret'),'password_confirm'=>'secret'),array('password'=>'secret','password_confirm'=>array('secret')),array('password'=>true,'password_confirm'=>true),array('password'=>'different','password_confirm'=>'values'),array('password'=>' value','password_confirm'=>'value')) as $values)
	{
		$_POST=array_merge(array('new_user'=>'1','submit'=>'Save'),$values); $passed=false; $caught=false; $mode='save';
		try { eval($gate); $passed=true; } catch(LegacyMailFailure $error) { $caught=in_array($error->getMessage(),array('required','mismatch'),true); }
		mail_check($caught && !$passed,'Missing/malformed/mismatched password stops actual creation dispatch before writes');
	}
	foreach(array('Unique-fixture!Q9','Grüße & <wörtlich> "Z9"',' leading and trailing ','0') as $password)
	{
		$_POST=array('new_user'=>1,'submit'=>'Save','password'=>$password,'password_confirm'=>$password); $mode='save'; eval($gate);
		mail_check($_POST['password']===$password && $_POST['password_confirm']===$password,'Valid input passes gate without mutating credential fields');
	}
	$_POST=array('new_user'=>0,'submit'=>'Save','password'=>'','password_confirm'=>''); $mode='save'; eval($gate);
	$begin=strpos($admin,"\t\t\$passwd_sql = '';"); $end=strpos($admin,'// End add - Admin add user MOD',$begin);
	mail_check($begin!==false && $end>$begin,'Actual final password assignment block found'); $body='namespace MailDeliveryFixture;'.substr($admin,$begin,$end-$begin);
	foreach(array(false,true) as $new_user)
	{
		$password=''; $password_confirm=''; $error=false; $error_msg=''; $force_new_passwd=false; eval($body);
		mail_check($passwd_sql==='' && $error===$new_user,'Blank password keeps existing account unchanged but never creates a default for a new account');
	}
	foreach(array(0,1) as $hashing)
	{
		$board_config['password_hashing']=$hashing;
		$password='Unique-fixture!Q9'; $password_confirm=$password; $new_user=true; $error=false; $error_msg=''; $force_new_passwd=false; eval($body);
		mail_check(!$error && \phpbb_password_verify('Unique-fixture!Q9',$password) && (($hashing && strlen($password)>32) || (!$hashing && strlen($password)===32)) && strpos($passwd_sql,'ct_last_pw_change =')!==false && strpos($passwd_sql,'user_passwd_change =')!==false,'Explicit password respects actual configured hash migration state and both age timestamps');
	}
	foreach(array('includes/usercp_register.php','includes/usercp_activate.php') as $file)
	{
		$source=file_get_contents($root.$file);
		mail_check(!preg_match('/[\'\"]PASSWORD[\'\"]\s*=>/',$source),'No credential variable supplied to account emailer: '.$file);
	}
	foreach(array('english','german') as $language)
	{
		foreach(glob($root.'language/lang_'.$language.'/email/*.tpl') as $path) { mail_check(strpos(file_get_contents($path),'{PASSWORD}')===false,'No distributed mail template requests plaintext PASSWORD: '.basename($path)); }
		$language_source=file_get_contents($root.'language/lang_'.$language.'/lang_admin.php');
		mail_check(strpos($language_source,"\$lang['New_user_password_required']")!==false && preg_match('/\$lang\[\'Create_user_explain\'\] = (.*);/',$language_source,$explain)===1 && substr_count($explain[1],'%s')===1,'Localized creation instructions have one reference account and no password substitution');
		foreach(array('user_welcome','user_welcome_inactive','admin_welcome_inactive','coppa_welcome_inactive') as $template)
		{
			foreach(array(false,true) as $smtp)
			{
				mail_fixture(); $mail_results=array(true); $mailer=new emailer($smtp);
				$mailer->from('forum@example.invalid'); $mailer->email_address('reader@example.invalid'); $mailer->use_template($template,$language);
				$secret='DO_NOT_TRANSMIT_PASSWORD_Q9!';
				$mailer->assign_vars(array('SITENAME'=>'Fixture Forum','WELCOME_MSG'=>'Welcome fixture','USERNAME'=>'Fixture Member','PASSWORD'=>$secret,'EMAIL_SIG'=>'Fixture signature','U_ACTIVATE'=>'https://fixture.invalid/profile.php?act_key=fixture-activation-token','FAX_INFO'=>'Fixture fax','MAIL_INFO'=>'Fixture post address','EMAIL_ADDRESS'=>'reader@example.invalid'));
				mail_check($mailer->send()===true,'Actual mailer renders and sends to isolated transport');
				$wire=$smtp?$smtp_written:implode("\n",$mail_calls[0]);
				mail_check(strpos($wire,$secret)===false && strpos($wire,'{PASSWORD}')===false && strpos($wire,'Fixture Member')!==false,'No password in real native/SMTP message body or headers: '.$language.'/'.$template);
				if($template==='user_welcome_inactive') { mail_check(strpos($wire,'fixture-activation-token')!==false,'Self-activation link remains usable'); }
				if($template==='coppa_welcome_inactive') { mail_check(strpos($wire,'Fixture fax')!==false && strpos($wire,'Fixture post address')!==false,'Parental consent return details preserved without password'); }
			}
		}
	}
	echo "Account credential disclosure, explicit creation password and mail rendering checks passed.\n";
}
finally { restore_error_handler(); }
