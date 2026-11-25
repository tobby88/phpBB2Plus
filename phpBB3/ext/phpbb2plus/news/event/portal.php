<?php
namespace phpbb2plus\news\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class portal implements EventSubscriberInterface
{
	private $articles;
	private $template;
	private $language;
	private $helper;
	private $config;

	public function __construct($articles, $template, $language, $helper, $config)
	{
		$this->articles = $articles; $this->template = $template; $this->language = $language;
		$this->helper = $helper; $this->config = $config;
	}

	public static function getSubscribedEvents() { return array('phpbb2plus.community.portal_content'=>'rows'); }
	public function rows($event)
	{
		$this->language->add_lang('news','phpbb2plus/news');
		$this->template->assign_var('U_PLUS_NEWS',$this->helper->route('phpbb2plus_news_home'));
		$limit = max(1,min(100,(int) $this->config['plus_news_page_size']));
		foreach ($this->articles->listing($this->articles->scope($event['forums']),$limit) as $article)
		{
			$this->template->assign_block_vars('plus_news',$article);
		}
	}
}
