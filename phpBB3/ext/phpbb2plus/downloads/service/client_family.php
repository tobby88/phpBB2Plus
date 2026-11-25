<?php
namespace phpbb2plus\downloads\service;

class client_family
{
	const OS = array('windows'=>'Windows','mac'=>'macOS','linux'=>'Linux','unix'=>'Unix','android'=>'Android','ios'=>'iOS','other'=>'');
	const BROWSERS = array('opera'=>'Opera','ie'=>'Internet Explorer','mozilla'=>'Mozilla','netscape'=>'Netscape',
		'firefox'=>'Firefox','edge'=>'Microsoft Edge','chrome'=>'Chrome','safari'=>'Safari','other'=>'');

	public static function imported($data, $prefix)
	{
		$os = $data[$prefix . '_os'] ?? '';
		$browser = $data[$prefix . '_browser'] ?? '';
		$os = is_string($os) ? strtolower($os) : '';
		$browser = is_string($browser) ? strtolower($browser) : '';
		$os = array('win'=>'windows','mac os'=>'mac','macintosh'=>'mac','iphone'=>'ios','ipad'=>'ios')[$os] ?? $os;
		$browser = array('edg'=>'edge','version'=>'safari')[$browser] ?? $browser;
		return array('client_os'=>isset(self::OS[$os]) ? $os : 'other','client_browser'=>isset(self::BROWSERS[$browser]) ? $browser : 'other');
	}

	public static function observed($agent, &$version = null)
	{
		// Browsers include other family names for compatibility. Match the more specific tokens first.
		$match = static function ($patterns,&$value = null) use ($agent) {
			$value = '';
			foreach ($patterns as $family=>$pattern)
			{
				if (preg_match($pattern,$agent,$found)) { $value = $found[1] ?? ''; return $family; }
			}
			return 'other';
		};
		return array('client_os'=>$match(array('android'=>'~Android~i','ios'=>'~iPhone|iPad|iPod~i','windows'=>'~Windows~i',
			'mac'=>'~Macintosh|Mac OS~i','linux'=>'~Linux~i','unix'=>'~BSD|SunOS|Unix~i')),
			'client_browser'=>$match(array('opera'=>'~(?:Opera[ /]|OPR/)([0-9.]{1,32})~i','edge'=>'~Edg(?:e|A|iOS)?/([0-9.]{1,32})~i','ie'=>'~MSIE ([0-9.]{1,32})|Trident/~i',
				'firefox'=>'~(?:Firefox|FxiOS)/([0-9.]{1,32})~i','chrome'=>'~(?:Chrome|CriOS)/([0-9.]{1,32})~i',
				'safari'=>'~Version/([0-9.]{1,32}).*Safari/|Safari/~i','netscape'=>'~Netscape~i','mozilla'=>'~Mozilla/~i'),$version));
	}
}
