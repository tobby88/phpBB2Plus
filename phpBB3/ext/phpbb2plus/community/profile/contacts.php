<?php
namespace phpbb2plus\community\profile;

/** Native profile field identifiers shared by the converter, UCP and board display. */
class contacts
{
	const FIELDS = array(
		'phpbb_facebook' => array('user_fb', 'Facebook', array('facebook.com'), 'https://www.facebook.com/'),
		'phpbb_twitter' => array('user_twr', 'X', array('x.com', 'twitter.com'), 'https://x.com/'),
		'plus_instagram' => array('user_ig', 'Instagram', array('instagram.com'), 'https://www.instagram.com/'),
		'plus_telegram' => array('user_tg', 'Telegram', array('t.me', 'telegram.me'), 'https://t.me/'),
		'plus_linkedin' => array('user_li', 'LinkedIn', array('linkedin.com'), 'https://www.linkedin.com/in/'),
		'plus_tiktok' => array('user_tt', 'TikTok', array('tiktok.com'), 'https://www.tiktok.com/@'),
		'plus_discord' => array('user_dc', 'Discord', array('discord.com', 'discordapp.com'), 'https://discord.com/users/'),
		'plus_signal' => array('user_signal', 'Signal', array('signal.me', 'signal.link'), ''),
		'plus_threema' => array('user_threema', 'Threema', array('threema.id'), 'https://threema.id/'),
	);

	public static function url($ident, $stored)
	{
		$value = trim(html_entity_decode((string) $stored, ENT_QUOTES, 'UTF-8'));
		if ($value === '' || !isset(self::FIELDS[$ident]) || preg_match('/[\x00-\x1f\x7f\\\\]/', $value)) { return ''; }
		$field = self::FIELDS[$ident];
		if (preg_match('~^(?:www\.)?[a-z0-9.-]+/~i', $value)) { $value = 'https://' . $value; }
		if (preg_match('~^https?://~i', $value))
		{
			$parts = parse_url($value);
			if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) { return ''; }
			$host = strtolower(rtrim($parts['host'], '.'));
			foreach ($field[2] as $allowed)
			{
				if ($host === $allowed || substr($host, -strlen('.' . $allowed)) === '.' . $allowed)
				{
					return htmlspecialchars(preg_replace('~^http://~i', 'https://', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				}
			}
			return '';
		}
		$value = ltrim($value, '@');
		if ($ident === 'plus_linkedin') { $value = preg_replace('~^in/~i', '', $value); }
		if ($ident === 'plus_discord' && !preg_match('/^[0-9]{5,30}$/D', $value)) { return ''; }
		if ($ident === 'plus_threema')
		{
			$value = strtoupper($value);
			if (!preg_match('/^[A-Z0-9]{8}$/D', $value)) { return ''; }
		}
		return $value !== '' && $field[3] !== '' ? $field[3] . rawurlencode($value) : '';
	}
}
