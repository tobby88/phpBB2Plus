<?php
namespace phpbb2plus\weblinks\event;

use phpbb2plus\weblinks\service\directory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class logos implements EventSubscriberInterface
{
	private $directory;
	private $config;
	private $helper;
	private $template;
	private $language;

	public function __construct($directory, $config, $helper, $template, $language)
	{
		$this->directory = $directory;
		$this->config = $config;
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.index_modify_page_title'=>'index','core.acp_board_config_edit_add'=>'settings');
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'features') { return; }
		$this->language->add_lang('directory','phpbb2plus/weblinks');
		$display = $event['display_vars'];
		$option = array('plus_wl_index_logos'=>array('lang'=>'WL_INDEX_LOGOS','validate'=>'bool','type'=>'radio:yes_no','explain'=>false));
		$display['vars'] = phpbb_insert_config_array($display['vars'],$option,array('after'=>'display_last_subject'));
		$event['display_vars'] = $display;
	}

	private function assign()
	{
		$this->language->add_lang('directory','phpbb2plus/weblinks');
		$visible = max(1,min(1000,(int) $this->config['plus_wl_display_logo_num']));
		$items = array();
		foreach ($this->directory->rotation_entries() as $row)
		{
			$logo = directory::image_url($row,$this->helper,true);
			if ($logo === '') { continue; }
			$title = directory::plain($row['title']);
			if (trim($title) === '') { $title = parse_url(directory::plain($row['destination']),PHP_URL_HOST); }
			$items[] = array('TITLE'=>$title, 'LOGO'=>$logo,
				'URL'=>$this->helper->route('phpbb2plus_weblinks_visit',array('id'=>(int) $row['link_id']),false));
		}
		$this->template->assign_vars(array('WL_FIRST_LOGOS'=>array_slice($items,0,$visible),'WL_MORE_LOGOS'=>array_slice($items,$visible),
			'WL_INTERVAL'=>max(250,min(2147483647,(int) $this->config['plus_wl_display_interval'])),
			'WL_WIDTH'=>max(1,min(1000,(int) $this->config['plus_wl_width'])),
			'WL_HEIGHT'=>max(1,min(1000,(int) $this->config['plus_wl_height'])),
			'U_WL'=>$this->helper->route('phpbb2plus_weblinks_home')));
	}

	public function index()
	{
		if (empty($this->config['plus_wl_index_logos'])) { return; }
		$this->assign();
		$this->template->assign_vars(array('S_WL_INDEX_LOGOS'=>true,
			'WL_BOARD_LOGO'=>directory::image_url(array('link_id'=>0,'local_logo'=>$this->config['plus_wl_local_logo'],
				'logo'=>$this->config['plus_wl_site_logo']),$this->helper)));
	}

	public function frame()
	{
		if (empty($this->config['plus_wl_index_logos'])) { throw new NotFoundHttpException(); }
		$this->assign();
		$response = $this->helper->render('@phpbb2plus_weblinks/logo_frame.html',$this->language->lang('WL_TITLE'));
		$response->headers->set('Cache-Control','private, no-store');
		$response->headers->set('Referrer-Policy','no-referrer');
		return $response;
	}
}
