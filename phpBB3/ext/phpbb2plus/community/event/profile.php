<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\profile\contacts;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class profile implements EventSubscriberInterface
{
	private $user;
	private $config;
	private $root;
	private $extension;

	public function __construct($user, $config, $root, $extension)
	{
		$this->user = $user;
		$this->config = $config;
		$this->root = $root;
		$this->extension = $extension;
	}

	static public function getSubscribedEvents()
	{
		return array('core.generate_profile_fields_template_data_before' => 'privacy',
			'core.generate_profile_fields_template_headlines' => 'headlines',
			'core.generate_profile_fields_template_data' => 'contact_links',
			'core.memberlist_modify_view_profile_template_vars' => 'profile_page',
			'core.get_user_rank_after' => 'user_rank', 'core.get_group_rank_after' => 'group_rank');
	}

	public function user_rank($event) { $event['user_rank_data'] = $this->localized_rank($event['user_rank_data']); }
	public function group_rank($event) { $event['group_rank_data'] = $this->localized_rank($event['group_rank_data']); }

	private function localized_rank($rank)
	{
		$src = $rank['img_src'];
		if ($src && preg_match('~(/plus-rank-[0-9]+-[a-f0-9]{16}-)(de|en)(\.(?:gif|png|jpe?g))$~D', $src, $parts))
		{
			$lang = strpos($this->user->lang_name, 'de') === 0 ? 'de' : 'en';
			$rank['img_src'] = substr($src, 0, -strlen($parts[0])) . $parts[1] . $lang . $parts[3];
			$rank['img'] = str_replace('src="' . $src . '"', 'src="' . $rank['img_src'] . '"', $rank['img']);
		}
		return $rank;
	}

	private function private_field($ident, $data)
	{
		return $ident === 'phpbb_location' || $ident === 'phpbb_website' || !empty($data['field_is_contact']) || isset(contacts::FIELDS[$ident]);
	}

	public function privacy($event)
	{
		if (!empty($this->user->data['is_registered'])) { return; }
		$profile = $event['profile_row'];
		foreach ($profile as $ident => $field)
		{
			if ($this->private_field($ident, $field['data'])) { unset($profile[$ident]); }
		}
		$event['profile_row'] = $profile;
	}

	public function headlines($event)
	{
		if (!empty($this->user->data['is_registered'])) { return; }
		$fields = $event['tpl_fields'];
		foreach ($fields as $key => $field)
		{
			$ident = $field['PROFILE_FIELD_IDENT'];
			if ($this->private_field($ident, $event['profile_cache'][$ident])) { unset($fields[$key]); }
		}
		$event['tpl_fields'] = array_values($fields);
	}

	public function contact_links($event)
	{
		if (!$event['use_contact_fields'] || empty($this->user->data['is_registered'])) { return; }
		$fields = $event['tpl_fields'];
		foreach ($fields['blockrow'] as &$entry)
		{
			$ident = $entry['PROFILE_FIELD_IDENT'];
			if (!isset(contacts::FIELDS[$ident])) { continue; }
			$value = $event['profile_row'][$ident]['value'];
			$url = contacts::url($ident, $value);
			$prefix = 'PROFILE_' . strtoupper($ident);
			$entry['PROFILE_FIELD_CONTACT'] = $fields['row'][$prefix . '_CONTACT'] = $url;
			$entry['S_PROFILE_CONTACT'] = $fields['row']['S_' . $prefix . '_CONTACT'] = $url !== '';
			$entry['PROFILE_FIELD_VALUE'] = $fields['row'][$prefix . '_VALUE'] =
				htmlspecialchars(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
		unset($entry);
		$event['tpl_fields'] = $fields;
	}

	public function profile_page($event)
	{
		if (!empty($this->user->data['is_registered'])) { return; }
		$vars = $event['template_ary'];
		$vars['U_EMAIL'] = $vars['U_JABBER'] = $vars['USER_JABBER'] = '';
		if ($this->config['allow_privmsg'])
		{
			$vars['U_PM'] = append_sid($this->root . 'ucp.' . $this->extension, 'i=pm&amp;mode=compose&amp;u=' . (int) $event['user_id']);
		}
		$event['template_ary'] = $vars;
	}
}
