<?php
namespace phpbb2plus\news\controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class board
{
	private $articles;
	private $config;
	private $request;
	private $helper;
	private $template;
	private $language;
	private $pagination;
	private $feed_helper;

	public function __construct($articles, $config, $request, $helper, $template, $language, $pagination, $feed_helper)
	{
		$this->articles = $articles; $this->config = $config; $this->request = $request; $this->helper = $helper;
		$this->template = $template; $this->language = $language; $this->pagination = $pagination; $this->feed_helper = $feed_helper;
	}

	private function navigation()
	{
		$this->language->add_lang('news','phpbb2plus/news');
		$this->template->assign_vars(array('U_PLUS_NEWS'=>$this->helper->route('phpbb2plus_news_home'),
			'U_NEWS_CATEGORIES'=>$this->helper->route('phpbb2plus_news_categories'),
			'U_NEWS_FEED'=>$this->config['plus_news_feed'] ? $this->helper->route('phpbb2plus_news_feed') : ''));
	}

	public function show($category)
	{
		$this->navigation();
		$category = (int) $category;
		$year = $this->request->variable('year',0);
		$month = $this->request->variable('month',0);
		$day = $this->request->variable('day',0);
		$key = utf8_substr(trim($this->request->variable('q','',true)),0,100);
		$forums = $this->articles->forums();
		$archive = $this->articles->scope($forums, $category, 0, 0, 0, $key);
		$scope = $archive . $this->articles->archive_period($year, $month, $day);
		$route = $category ? 'phpbb2plus_news_category' : 'phpbb2plus_news_home';
		$params = $category ? array('category'=>$category) : array();
		$action = $this->helper->route($route,$params);
		// phpBB has escaped the request for HTML. Route parameters require the original text.
		if ($key !== '') { $params['q'] = htmlspecialchars_decode($key, ENT_QUOTES); }
		$total = $this->archive_navigation($archive, $route, $params, $year, $month, $day);
		if ($category && !$total && !$this->articles->count($this->articles->scope($forums,$category))) { throw new NotFoundHttpException(); }
		$filters = array_filter(array('year'=>$year,'month'=>$month,'day'=>$day));
		$limit = max(1,min(100,(int) $this->config['plus_news_page_size']));
		$start = $this->pagination->validate_start(max(0,$this->request->variable('start',0)),$limit,$total);
		$this->pagination->generate_template_pagination($this->helper->route($route,$params + $filters),'pagination','start',$total,$limit,$start);
		foreach ($this->articles->listing($scope,$limit,$start) as $row) { $this->template->assign_block_vars('plus_news',$row); }
		$this->template->assign_vars(array('NEWS_ACTION'=>$action, 'NEWS_YEAR'=>$year ?: '', 'NEWS_MONTH'=>$month ?: '',
			'NEWS_DAY'=>$day ?: '', 'NEWS_QUERY'=>$key));
		return $this->helper->render('@phpbb2plus_news/list.html',$this->language->lang('PLUS_NEWS_ARCHIVE'));
	}

	private function archive_navigation($scope, $route, array $params, $year, $month, $day)
	{
		$this->template->assign_var('U_NEWS_ALL_DATES', $year ? $this->helper->route($route, $params) : '');
		$total = 0;
		foreach ($this->articles->archive_dates($scope) as $item_year => $months)
		{
			$year_params = $params + array('year' => $item_year);
			$this->template->assign_block_vars('archive_years', array('LABEL' => $item_year,
				'URL' => $this->helper->route($route, $year_params), 'COUNT' => array_sum(array_map('array_sum', $months)),
				'CURRENT' => $item_year === $year && !$month));
			if ($year && $item_year !== $year) { continue; }
			foreach ($months as $item_month => $days)
			{
				$count = array_sum($days);
				// The same published dates supply pagination, without recounting the topic and post joins.
				if (!$month || $item_month === $month) { $total += $day ? ($days[$day] ?? 0) : $count; }
				$month_params = $year_params + array('month' => $item_month);
				$month_name = \DateTimeImmutable::createFromFormat('!n', (string) $item_month)->format('F');
				$month_name = $this->language->lang(array('datetime', $month_name));
				$this->template->assign_block_vars('archive_years.months', array('LABEL' => $month_name,
					'URL' => $this->helper->route($route, $month_params), 'COUNT' => $count,
					'CURRENT' => $item_year === $year && $item_month === $month && !$day));
				if ($item_year !== $year || $item_month !== $month) { continue; }
				foreach ($days as $item_day => $total)
				{
					$this->template->assign_block_vars('archive_years.months.days', array(
						'LABEL' => $this->language->lang('PLUS_NEWS_ARCHIVE_DAY', $item_day, $month_name),
						'URL' => $this->helper->route($route, $month_params + array('day' => $item_day)),
						'COUNT' => $total, 'CURRENT' => $item_day === $day));
				}
			}
		}
		return $total;
	}

	public function categories()
	{
		$this->navigation();
		foreach ($this->articles->categories($this->articles->scope($this->articles->forums())) as $category)
		{
			$this->template->assign_block_vars('news_categories',$category);
		}
		return $this->helper->render('@phpbb2plus_news/categories.html',$this->language->lang('PLUS_NEWS_CATEGORIES'));
	}

	public function feed()
	{
		if (!$this->config['plus_news_feed']) { throw new NotFoundHttpException(); }
		$dom = new \DOMDocument('1.0','UTF-8');
		$rss = $dom->appendChild($dom->createElement('rss'));
		$rss->setAttribute('version','2.0');
		$channel = $rss->appendChild($dom->createElement('channel'));
		$add = static function ($parent, $name, $value) use ($dom) {
			$element = $parent->appendChild($dom->createElement($name));
			$element->appendChild($dom->createTextNode((string) $value));
		};
		$add($channel,'title',html_entity_decode($this->config['sitename'],ENT_QUOTES,'UTF-8'));
		$add($channel,'link',$this->feed_helper->get_board_url() . '/');
		$add($channel,'description',html_entity_decode($this->config['plus_news_feed_description'] ?: $this->config['site_desc'],ENT_QUOTES,'UTF-8'));
		if ($this->config['plus_news_feed_language']) { $add($channel,'language',str_replace('_','-',$this->config['plus_news_feed_language'])); }
		if ($this->config['plus_news_feed_category']) { $add($channel,'category',html_entity_decode($this->config['plus_news_feed_category'],ENT_QUOTES,'UTF-8')); }
		$image_url = html_entity_decode((string) $this->config['plus_news_feed_image'],ENT_QUOTES,'UTF-8');
		if ($image_url !== '' && $this->config['plus_news_feed_image_desc'] !== '')
		{
			if (preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:gif|png|jpe?g|webp)$~iD',$image_url))
			{
				$image_url = $this->feed_helper->get_board_url() . '/' . $image_url;
			}
			if (filter_var($image_url,FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($image_url,PHP_URL_SCHEME)),array('https','http'),true))
			{
				$image = $channel->appendChild($dom->createElement('image'));
				$add($image,'title',html_entity_decode($this->config['sitename'],ENT_QUOTES,'UTF-8'));
				$add($image,'url',$image_url);
				$add($image,'link',$this->feed_helper->get_board_url() . '/');
				$add($image,'description',html_entity_decode($this->config['plus_news_feed_image_desc'],ENT_QUOTES,'UTF-8'));
			}
		}
		$add($channel,'ttl',max(0,(int) $this->config['plus_news_feed_ttl']));
		$scope = $this->articles->scope($this->articles->forums(),max(0,$this->request->variable('category',0)));
		foreach ($this->articles->listing($scope,max(1,min(100,(int) $this->config['plus_news_feed_limit'])),0,true) as $row)
		{
			$item = $channel->appendChild($dom->createElement('item'));
			$add($item,'title',html_entity_decode($row['TITLE'],ENT_QUOTES,'UTF-8'));
			$add($item,'link',$row['U_TOPIC']);
			$add($item,'guid',$row['U_TOPIC']);
			$add($item,'pubDate',gmdate(DATE_RSS,$row['TIME']));
			$add($item,'category',html_entity_decode($row['CATEGORY'],ENT_QUOTES,'UTF-8'));
			$add($item,'description',$row['BODY']);
			$add($item,'comments',$row['U_TOPIC']);
		}
		return new Response($dom->saveXML(),200,array('Content-Type'=>'application/rss+xml; charset=UTF-8', 'Cache-Control'=>'private, no-store','Vary'=>'Cookie'));
	}
}
