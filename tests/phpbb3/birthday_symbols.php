<?php
if (PHP_SAPI !== 'cli') { exit(2); }
use phpbb2plus\community\profile\birthdate;
$expect = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$tests = dirname(__DIR__, 2);
if ($argc === 2 && $argv[1] === 'calendar')
{
	require $tests . '/phpBB3/ext/phpbb2plus/community/profile/birthdate.php';
	$transitions = array('0121'=>'aquarius','0220'=>'pisces','0321'=>'aries','0421'=>'taurus','0521'=>'gemini','0622'=>'cancer',
		'0723'=>'leo','0824'=>'virgo','0923'=>'libra','1023'=>'scorpio','1123'=>'sagittarius','1222'=>'capricorn');
	$year = new DateTimeImmutable('2000-01-01');
	$previous = 'capricorn';
	foreach ($transitions as $md=>$sign)
	{
		$date = $year->setDate(2000, (int)substr($md,0,2), (int)substr($md,2,2));
		$before = $date->modify('-1 day');
		$expect(birthdate::western((int)$before->format('n'),(int)$before->format('j')) === $previous &&
			birthdate::western((int)$date->format('n'),(int)$date->format('j')) === $sign, 'Changed Plus western transition ' . $md);
		$previous = $sign;
	}
	foreach (array(array(1901,2,19,'rat','buffalo'),array(1938,1,31,'buffalo','tiger'),array(2008,2,7,'pig','rat'),
		array(2011,2,3,'tiger','cat'),array(2024,2,10,'cat','dragon'),array(2025,1,29,'dragon','snake'),array(2100,2,9,'goat','monkey')) as $case)
	{
		list($y,$m,$d,$old,$new) = $case;
		$before = $year->setDate($y,$m,$d)->modify('-1 day');
		$expect(birthdate::chinese($y,(int)$before->format('n'),(int)$before->format('j')) === $old &&
			birthdate::chinese($y,$m,$d) === $new, 'Incorrect Chinese New Year in ' . $y);
	}
	$expect(birthdate::parts('29- 2-   0',$year) === array(29,2,0) && birthdate::parts('29- 2-1999',$year) === null &&
		birthdate::parts(' 2- 1-2000',$year) === null && birthdate::chinese(0,1,1) === null &&
		birthdate::chinese(1900,12,31) === null && birthdate::chinese(2101,1,1) === null, 'Unsupported dates acquired a birth year or symbol');
	return;
}
if (getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$key = 'test_birthday_symbols';
$text = $phpbb_container->get('config_text');
$dates = array('partial'=>'29- 2-   0','cat'=>' 3- 2-2011','invalid'=>'31- 2-1970','unset'=>' 0- 0-   0');
if (isset($dates[$argv[2]]))
{
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_birthday='" . $dates[$argv[2]] . "' WHERE user_id=2");
	return;
}
switch ($argv[2])
{
	case 'prepare':
		$files = array_merge(glob($tests.'/phpBB2/templates/fisubsilversh/images/icon_zodiac_*.gif'), glob($tests.'/phpBB2/templates/fisubsilversh/images/icon_chinese_*.gif'));
		$expect(count($files) === 24, 'The Plus birthday artwork is incomplete');
		foreach ($files as $file)
		{
			$built = './styles/fisubsilversh/theme/images/' . basename($file);
			$expect(is_file($built) && hash_file('sha256',$built) === hash_file('sha256',$file), 'Changed birthday symbol ' . basename($file));
		}
		$expect($text->get($key) === null, 'Another birthday-symbol fixture is active');
		$r = $db->sql_query('SELECT user_birthday FROM ' . USERS_TABLE . ' WHERE user_id=2');
		$original = $db->sql_fetchfield('user_birthday'); $db->sql_freeresult($r);
		$text->set($key,json_encode(array($original,$config['allow_birthdays'],$config['plus_birthday_greeting'])));
		$config->set('allow_birthdays',1); $config->set('plus_birthday_greeting',0);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_birthday='30- 1-1938' WHERE user_id=2");
		break;
	case 'off':
		$config->set('allow_birthdays',0);
		break;
	case 'restore':
		list($birthday,$enabled,$greeting) = json_decode($text->get($key),true);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_birthday='" . $db->sql_escape($birthday) . "' WHERE user_id=2");
		$config->set('allow_birthdays',$enabled); $config->set('plus_birthday_greeting',$greeting);
		$text->delete($key);
		break;
	default: exit(2);
}
