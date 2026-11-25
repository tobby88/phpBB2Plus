<?php
namespace phpbb2plus\news\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class settings implements EventSubscriberInterface
{
	private $language;

	public function __construct($language) { $this->language = $language; }
	public static function getSubscribedEvents() { return array('core.acp_board_config_edit_add'=>'fields'); }

	public function fields($event)
	{
		switch ($event['mode'])
		{
			case 'post':
				$limits = array('publish'=>'bool','page_size'=>'int:1:100','trim'=>'int:0:1000000','title_trim'=>'int:0:255');
				break;
			case 'feed':
				$limits = array('feed'=>'bool','feed_limit'=>'int:1:100','feed_abstract'=>'bool','feed_description'=>'string:0:255',
					'feed_language'=>'string:0:32','feed_ttl'=>'int:0:10080','feed_category'=>'string:0:255',
					'feed_image'=>'string:0:255','feed_image_desc'=>'string:0:255');
				break;
			default: return;
		}
		$this->language->add_lang('news','phpbb2plus/news');
		$news = array('legend_plus_news'=>'PLUS_NEWS');
		foreach ($limits as $key=>$validation)
		{
			$range = explode(':',$validation);
			$type = $range[0] === 'bool' ? 'radio:yes_no' : ($range[0] === 'int' ? 'number:' . $range[1] . ':' . $range[2] : 'text:40:' . $range[2]);
			$news['plus_news_' . $key] = array('lang'=>'PLUS_NEWS_SETTING_' . strtoupper($key),'validate'=>$validation,'type'=>$type,'explain'=>false);
		}
		$display = $event['display_vars'];
		$fields = array();
		foreach ($display['vars'] as $name=>$row)
		{
			if ($row === 'ACP_SUBMIT_CHANGES') { $fields += $news; }
			$fields[$name] = $row;
		}
		$display['vars'] = $fields + $news;
		$event['display_vars'] = $display;
	}
}
