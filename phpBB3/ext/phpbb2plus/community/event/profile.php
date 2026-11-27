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
	private $flags;
	private $paths;
	private $hide_on_profile = array();

	public function __construct($user, $config, $root, $extension, $flags, $paths)
	{
		$this->user = $user;
		$this->config = $config;
		$this->root = $root;
		$this->extension = $extension;
		$this->flags = $flags;
		$this->paths = $paths;
	}

	static public function getSubscribedEvents()
	{
		return array('core.generate_profile_fields_template_data_before' => 'privacy',
			'core.generate_profile_fields_template_headlines' => 'headlines',
			'core.generate_profile_fields_template_data' => 'contact_links',
			'core.memberlist_modify_view_profile_template_vars' => 'profile_page',
			'core.memberlist_view_profile' => 'custom_profile_visibility',
			'core.user_setup_after' => 'birthday_list',
			'core.get_user_rank_after' => 'user_rank', 'core.get_group_rank_after' => 'group_rank');
	}

	public function birthday_list()
	{
		// This assignment affects only the current request. The board's birthday
		// preference and the native registration/profile editors stay available.
		if (empty($this->user->data['is_registered'])) { $this->config['load_birthdays'] = 0; }
	}

	public function user_rank($event) { $event['user_rank_data'] = $this->localized_rank($event['user_rank_data']); }
	public function group_rank($event) { $event['group_rank_data'] = $this->localized_rank($event['group_rank_data']); }

	private function localized_rank($rank)
	{
		$src = $rank['img_src'];
		$rank['img_src'] = \phpbb2plus\community\service\rank_image::localize($src, $this->user->lang_name);
		if ($rank['img_src'] !== $src)
		{
			$rank['img'] = str_replace('src="' . $src . '"', 'src="' . $rank['img_src'] . '"', $rank['img']);
		}
		return $rank;
	}

	private function private_field($ident, $data)
	{
		return $ident === 'phpbb_location' || $ident === 'plus_country' || $ident === 'phpbb_website' || !empty($data['field_is_contact']) || isset(contacts::FIELDS[$ident]);
	}

	public function privacy($event)
	{
		$profile = $event['profile_row'];
		foreach ($profile as $ident => $field)
		{
			if (!empty($field['data']['plus_source_id']) && empty($field['data']['plus_view_profile'])) { $this->hide_on_profile[$ident] = true; }
			if (empty($this->user->data['is_registered']) && $this->private_field($ident, $field['data'])) { unset($profile[$ident]); }
		}
		$event['profile_row'] = $profile;
	}

	public function custom_profile_visibility($event)
	{
		$fields = $event['profile_fields'];
		if (!$this->hide_on_profile || empty($fields['blockrow'])) { return; }
		foreach ($fields['blockrow'] as $key=>$field)
		{
			$ident = $field['PROFILE_FIELD_IDENT'];
			if (!isset($this->hide_on_profile[$ident])) { continue; }
			unset($fields['blockrow'][$key]);
			$prefix = 'PROFILE_' . strtoupper($ident);
			foreach (array_keys($fields['row']) as $name)
			{
				if (strpos($name,$prefix . '_') === 0 || $name === 'S_' . $prefix || $name === 'S_' . $prefix . '_CONTACT')
				{
					unset($fields['row'][$name]);
				}
			}
		}
		$fields['blockrow'] = array_values($fields['blockrow']);
		$event['profile_fields'] = $fields;
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
		if (empty($this->user->data['is_registered'])) { return; }
		$fields = $event['tpl_fields'];
		foreach ($fields['blockrow'] as &$entry)
		{
			$ident = $entry['PROFILE_FIELD_IDENT'];
			if ($ident === 'plus_country')
			{
				$label = $entry['PROFILE_FIELD_VALUE'];
				$image = $this->flags->image($entry['PROFILE_FIELD_VALUE_RAW'], $label);
				if ($image !== '')
				{
					$src = htmlspecialchars($this->paths->get_web_root_path() . 'images/plusflags/' . $image, ENT_QUOTES, 'UTF-8');
					$entry['PROFILE_FIELD_VALUE'] = $fields['row']['PROFILE_PLUS_COUNTRY_VALUE'] =
						'<img src="' . $src . '" alt="" loading="lazy" /> ' . $label;
				}
				continue;
			}
			if (!$event['use_contact_fields'] || !isset(contacts::FIELDS[$ident])) { continue; }
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
