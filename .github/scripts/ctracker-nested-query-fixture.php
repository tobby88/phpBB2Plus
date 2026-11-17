<?php
// Actual constructor/formatter and redaction helpers. No logs, DB or requests.
$nested_cases = 0;
foreach (array('sid','token','reset_key','account[password]','user_actkey') as $key)
{
	foreach (array('&',';','&amp;') as $separator)
	{
		$value = 'profile.php?mode=viewprofile' . $separator . $key . '=SyntheticNestedValue';
		for ($depth = 0; $depth < 4; $depth++)
		{
			$value = rawurlencode($value);
			$query = 'page=2&redirect=' . $value . '&highlight=ordinary%20context';
			ct_redaction_assert($manager->redact_query_string($query) === 'page=2&redirect=REDACTED&highlight=ordinary%20context', 'encoded nested credential and harmless context');
			$nested_cases++;
		}
	}
}
foreach (array('viewtopic.php?p=7&highlight=Gr%C3%BC%C3%9Fe','profile.php?mode=viewprofile&u=2','page=2','ordinary + text','100% complete','foo=bar','x=y&foo=bar','',str_repeat('x',8183)) as $value)
{
	$query = 'redirect=' . rawurlencode($value);
	ct_redaction_assert($manager->redact_query_string($query) === $query, 'harmless encoded context stays byte-identical');
	$nested_cases++;
}
$query='redirect='.str_repeat('x',9000);ct_redaction_assert($manager->redact_query_string($query)===substr($query,0,8192),'existing byte ceiling stays bounded');$nested_cases++;
foreach (array('%2573id','account%255Btoken%255D','reset%255Fkey') as $key)
{
	ct_redaction_assert($manager->redact_query_string('page=2&'.$key.'=SyntheticOuterValue') === 'page=2&'.$key.'=REDACTED', 'encoded outer credential name');
	$nested_cases++;
}
foreach (array('return=' . rawurlencode('login.php?redirect=' . rawurlencode('profile.php?sid=SyntheticTwiceValue')), 'payload=' . rawurlencode('token=SyntheticValue'), 'data=' . rawurlencode('next.php#token=SyntheticFragmentValue'), 'return='.rawurlencode('next='.rawurlencode('sid=SyntheticBareQueryValue'))) as $query)
{
	ct_redaction_assert(substr($manager->redact_query_string($query),strpos($query,'=')+1) === 'REDACTED', 'nested values, queries and fragments');
	$nested_cases++;
}
$deep='profile.php?sid=SyntheticDeepValue';for($i=0;$i<12;$i++){$deep=rawurlencode($deep);}
ct_redaction_assert($manager->redact_query_string('redirect='.$deep)==='redirect=REDACTED','bounded decoding refuses excessive nesting');$nested_cases++;
$HTTP_SERVER_VARS=array('PHP_SELF'=>'/login.php','QUERY_STRING'=>'page=2&redirect='.rawurlencode('viewtopic.php?p=7&sid=SyntheticRequestValue'),'HTTP_REFERER'=>'https://example.invalid/login.php?return='.rawurlencode('profile.php?token=SyntheticRefererValue'),'REMOTE_ADDR'=>'192.0.2.1');$HTTP_ENV_VARS=array();
$actual=new log_manager();$fields=explode('|||',$actual->to_string());
ct_redaction_assert(count($fields)===7&&$fields[2]==='/login.php?page=2&redirect=REDACTED'&&$fields[3]==='https://example.invalid/login.php?return=REDACTED','actual constructor and seven-column formatter mask nested values');$nested_cases++;
$old_limit=ini_get('pcre.backtrack_limit');
try{ini_set('pcre.backtrack_limit','1');ct_redaction_assert(strpos($manager->redact_query_string('redirect='.rawurlencode('profile.php?sid=SyntheticLimitedValue')),'SyntheticLimitedValue')===false,'limited regex never exposes a nested credential');$nested_cases++;}finally{ini_set('pcre.backtrack_limit',$old_limit);}
echo 'CrackerTracker nested query privacy: ',$nested_cases," cases PASS; actual constructor/formatter, encoded keys/URLs/separators, bounded nesting, harmless context and regex failure; no log writes.\n";
