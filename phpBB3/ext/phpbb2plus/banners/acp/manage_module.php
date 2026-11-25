<?php
namespace phpbb2plus\banners\acp;

use phpbb\request\request_interface;
use phpbb2plus\banners\service\artwork;
use phpbb2plus\banners\service\catalog;
use phpbb2plus\banners\service\management;

class manage_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	private $request;
	private $template;
	private $language;
	private $manager;

	private static function plain($value) { return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'); }

	private function input($name, $length)
	{
		$value = $this->request->variable($name,'',true,request_interface::POST);
		if (strlen($value) > $length) { throw new \DomainException('PLUS_BN_INVALID'); }
		return trim($value);
	}

	private function number($key, $min, $max)
	{
		$value = $this->input($key,12);
		if (!preg_match('/^-?[0-9]{1,10}$/D',$value) || (int) $value < $min || (int) $value > $max) { throw new \DomainException('PLUS_BN_INVALID'); }
		return (int) $value;
	}

	private function hour($key)
	{
		$value = $this->input($key,5);
		if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D',$value,$parts)) { throw new \DomainException('PLUS_BN_INVALID'); }
		return (int) ($parts[1] . $parts[2]);
	}

	private function date($key)
	{
		$value = $this->input($key,10);
		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$value,$parts) || !checkdate((int) $parts[2],(int) $parts[3],(int) $parts[1]))
		{
			throw new \DomainException('PLUS_BN_INVALID');
		}
		return (int) ($parts[1] . $parts[2] . $parts[3]);
	}

	private function choices($var, $values, $selected)
	{
		foreach ($values as $key=>$label)
		{
			$this->template->assign_block_vars($var,array('KEY'=>$key,'LABEL'=>$label,'SELECTED'=>(string) $key === (string) $selected));
		}
	}

	private function values($row, $assets, $owner)
	{
		$values = array();
		foreach (array('display_name'=>65535,'description'=>4096,'target_url'=>8192,'notes'=>65535) as $key=>$size)
		{
			$text = $this->input($key,$size);
			$values[$key] = self::plain($text) === self::plain($row[$key]) ? $row[$key] : $text;
		}
		if (!catalog::external($values['target_url'])) { throw new \DomainException('PLUS_BN_INVALID'); }
		foreach (array('spot'=>array(0,19),'media_type'=>array(0,6),'forum_id'=>array(0,2147483647),'weight'=>array(0,2147483647),
			'width'=>array(0,4096),'height'=>array(0,4096),'time_mode'=>array(0,6),'audience'=>array(-1,2),
			'audience_mode'=>array(0,3),'filter_seconds'=>array(0,2147483647)) as $key=>$bounds)
		{
			$values[$key] = $this->number($key,$bounds[0],$bounds[1]);
		}
		$type = $values['media_type'];
		$mode = $values['time_mode'];
		if (!in_array($type,array(0,2,6),true) || !in_array($mode,array(0,2,4,6),true)) { throw new \DomainException('PLUS_BN_INVALID'); }
		$values['active'] = $this->request->is_set_post('active') ? 1 : 0;
		$values['filter_clicks'] = $this->request->is_set_post('filter_clicks') ? 1 : 0;
		$account = $this->input('owner',1020);
		$values['owner_id'] = self::plain($account) === $owner ? $row['owner_id'] : $this->manager->owner($account);
		$values['time_begin'] = $mode ? $this->hour('time_begin') : 0;
		$values['time_end'] = $mode ? $this->hour('time_end') : 0;
		$values['date_begin'] = $values['date_end'] = 0;
		if ($mode === 4)
		{
			$values['date_begin'] = $this->number('week_begin',0,6);
			$values['date_end'] = $this->number('week_end',0,6);
		}
		elseif ($mode === 6)
		{
			$values['date_begin'] = $this->date('date_begin');
			$values['date_end'] = $this->date('date_end');
			if ($values['date_end'] < $values['date_begin']) { throw new \DomainException('PLUS_BN_INVALID'); }
		}

		$choice = self::plain($this->input('artwork',255));
		$media = array('asset_name'=>'','asset_bytes'=>0,'asset_mime'=>'','remote_asset'=>'');
		if ($type === 2)
		{
			if (trim(self::plain($values['display_name'])) === '') { throw new \DomainException('PLUS_BN_INVALID'); }
		}
		elseif ($choice === 'keep' && $row['banner_id'] && $type === (int) $row['media_type'])
		{
			$media = array_intersect_key($row,$media);
			$values['display_name'] = $row['display_name'];
		}
		elseif (isset($assets[$choice]))
		{
			$media = $assets[$choice];
			if (($media['asset_mime'] === 'application/x-shockwave-flash') !== ($type === 6)) { throw new \DomainException('PLUS_BN_INVALID'); }
			$values['display_name'] = utf8_htmlspecialchars($choice);
		}
		elseif ($choice === '' && ($url = catalog::external($values['display_name']))) { $media['remote_asset'] = utf8_htmlspecialchars($url); }
		else { throw new \DomainException('PLUS_BN_INVALID'); }
		return $values + $media;
	}

	private static function title($row)
	{
		$name = (int) $row['media_type'] === 2 || $row['description'] === '' ? $row['display_name'] : $row['description'];
		return self::plain($name);
	}

	private function listing($forums, $config, $user, $log)
	{
		$form = 'phpbb2plus_banner_display';
		add_form_key($form);
		if ($this->request->is_set_post('display'))
		{
			if (!check_form_key($form)) { trigger_error('FORM_INVALID',E_USER_WARNING); }
			$next = $this->request->is_set_post('enabled') ? 1 : 0;
			if ((int) $config['plus_banners_enabled'] !== $next)
			{
				$config->set('plus_banners_enabled',$next);
				$log->add('admin',$user->data['user_id'],$user->ip,'LOG_PLUS_BN_DISPLAY');
			}
			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}
		$limit = 50;
		list($rows,$total,$start) = $this->manager->rows($forums,$this->request->variable('start',0),$limit);
		$places = $this->language->lang_raw('PLUS_BN_SPOTS');
		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('banners',array('TITLE'=>self::title($row),'SPOT'=>$places[$row['spot']] ?? $row['spot'],
				'ACTIVE'=>(bool) $row['active'],'CLICKS'=>management::whole($row['clicks']),'VIEWS'=>management::whole($row['views']),
				'URL'=>$this->u_action . '&amp;banner=' . (int) $row['banner_id']));
		}
		$this->template->assign_vars(array('ENABLED'=>(bool) $config['plus_banners_enabled'],
			'U_PREV'=>$start ? $this->u_action . '&amp;start=' . max(0,$start-$limit) : '',
			'U_NEXT'=>$start+$limit < $total ? $this->u_action . '&amp;start=' . ($start+$limit) : ''));
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $phpbb_root_path;
		$this->request = $phpbb_container->get('request');
		$this->language = $phpbb_container->get('language');
		$this->template = $phpbb_container->get('template');
		$this->manager = $phpbb_container->get('phpbb2plus.banners.management');
		$user = $phpbb_container->get('user');
		$config = $phpbb_container->get('config');
		$log = $phpbb_container->get('log');
		$this->tpl_name = 'acp_plus_banners';
		$this->page_title = 'ACP_PLUS_BANNERS';
		$this->language->add_lang('info_acp_banners','phpbb2plus/banners');
		$id = max(0,$this->request->variable('banner',0,false,request_interface::GET));
		$forums = $this->manager->forums();
		$forums[0] = $this->language->lang('PLUS_BN_ALL_FORUMS');
		$this->template->assign_vars(array('U_LIST'=>$this->u_action,'U_NEW'=>$this->u_action . '&amp;new=1'));
		if (!$id && !$this->request->variable('new',0,false,request_interface::GET)) { $this->listing($forums,$config,$user,$log); return; }
		try
		{
			$row = $id ? $this->manager->row($id,$forums) : array('banner_id'=>0,'display_name'=>'','target_url'=>'','description'=>'','notes'=>'',
				'owner_id'=>(int) $user->data['user_id'],'active'=>1,'spot'=>16,'forum_id'=>0,'media_type'=>2,'asset_name'=>'','remote_asset'=>'',
				'weight'=>50,'width'=>0,'height'=>0,'time_mode'=>0,'time_begin'=>0,'time_end'=>2359,'date_begin'=>0,'date_end'=>0,
				'audience'=>-1,'audience_mode'=>2,'filter_clicks'=>0,'filter_seconds'=>600,'clicks'=>'0','views'=>'0');
			$form = 'phpbb2plus_banner_edit_' . $id;
			add_form_key($form);
			$owner = $this->manager->username($row['owner_id']);
			$assets = artwork::available($phpbb_root_path . trim($config['upload_path'],'/'));
			$remove = $id && $this->request->is_set_post('remove');
			if ($remove || $this->request->is_set_post('save'))
			{
				$revision = $this->input('revision',64);
				if (!$remove || !confirm_box(true))
				{
					if (!check_form_key($form)) { trigger_error('FORM_INVALID',E_USER_WARNING); }
					if ($remove)
					{
						confirm_box(false,'PLUS_BN_DELETE',build_hidden_fields(array('remove'=>1,'revision'=>$revision)));
						return;
					}
				}
				$values = $counters = array();
				if (!$remove)
				{
					$values = $this->values($row,$assets,$owner);
					foreach (array('views','clicks') as $key)
					{
						$counters[$key] = array(management::counter($this->input('original_' . $key,20)),management::counter($this->input($key,20)));
					}
				}
				if ($this->manager->write($id,$revision,$values,$counters,$this->input('submission',32),$remove))
				{
					$log->add('admin',$user->data['user_id'],$user->ip,$remove ? 'LOG_PLUS_BN_REMOVED' : ($id ? 'LOG_PLUS_BN_EDITED' : 'LOG_PLUS_BN_ADDED'),
						false,array(utf8_htmlspecialchars(self::title($remove ? $row : $values))));
				}
				trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
			}

			$this->choices('spots',$this->language->lang_raw('PLUS_BN_SPOTS'),$row['spot']);
			$this->choices('formats',$this->language->lang_raw('PLUS_BN_FORMATS'),$row['media_type']);
			$this->choices('levels',$this->language->lang_raw('PLUS_BN_LEVELS'),$row['audience']);
			$this->choices('matches',$this->language->lang_raw('PLUS_BN_MATCHES'),$row['audience_mode']);
			$this->choices('schedules',$this->language->lang_raw('PLUS_BN_MODES'),$row['time_mode']);
			$this->choices('forums',$forums,$row['forum_id']);
			$days = $this->language->lang_raw('PLUS_BN_DAYS');
			$this->choices('begin_days',$days,(int) $row['time_mode'] === 4 ? $row['date_begin'] : 0);
			$this->choices('end_days',$days,(int) $row['time_mode'] === 4 ? $row['date_end'] : 6);
			$art = array(''=>$this->language->lang('PLUS_BN_FROM_LABEL'));
			$keep = $id && (int) $row['media_type'] !== 2 && $row['remote_asset'] === '';
			if ($keep) { $art['keep'] = $this->language->lang('PLUS_BN_KEEP') . ' (' . self::plain($row['display_name']) . ')'; }
			foreach ($assets as $name=>$asset) { $art[$name] = $name; }
			$this->choices('artworks',$art,$keep ? 'keep' : '');
			$view = array();
			foreach (array('display_name','description','target_url','notes') as $key) { $view[strtoupper($key)] = self::plain($row[$key]); }
			foreach (array('active','filter_clicks','weight','width','height','filter_seconds') as $key) { $view[strtoupper($key)] = (int) $row[$key]; }
			foreach (array('time_begin','time_end') as $key) { $view[strtoupper($key)] = substr_replace(sprintf('%04d',$row[$key]),':',2,0); }
			foreach (array('date_begin','date_end') as $key)
			{
				$view[strtoupper($key)] = (int) $row['time_mode'] === 6 ? preg_replace('/^(\d{4})(\d{2})(\d{2})$/','$1-$2-$3',(string) $row[$key]) : '';
			}
			$this->template->assign_vars($view + array('S_EDITOR'=>true,'S_EXISTING'=>(bool) $id,'OWNER'=>$owner,
				'U_SAVE'=>$id ? $this->u_action . '&amp;banner=' . $id : $this->u_action . '&amp;new=1',
				'CLICKS'=>management::whole($row['clicks']),'VIEWS'=>management::whole($row['views']),
				'REVISION'=>$id ? management::revision($row) : '', 'SUBMISSION'=>$id ? '' : bin2hex(random_bytes(16))));
		}
		catch (\DomainException $error) { trigger_error($error->getMessage(),E_USER_WARNING); }
	}
}
