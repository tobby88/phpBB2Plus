<?php
namespace phpbb2plus\gallery\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class portal implements EventSubscriberInterface
{
	private $access;
	private $byline;
	private $db;
	private $config;
	private $text;
	private $template;
	private $helper;
	private $language;
	private $user;
	private $prefix;

	public function __construct($access, $byline, $db, $config, $text, $template, $helper, $language, $user, $prefix)
	{
		$this->access = $access;
		$this->byline = $byline;
		$this->db = $db;
		$this->config = $config;
		$this->text = $text;
		$this->template = $template;
		$this->helper = $helper;
		$this->language = $language;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	public static function getSubscribedEvents() { return array('phpbb2plus.community.portal_content'=>'pictures'); }

	public function pictures()
	{
		$limit = min(100, (int) $this->config['plus_portal_pictures']);
		if ($limit <= 0) { return; }
		$selected = (string) $this->text->get('plus_portal_picture_categories');
		$categories = $this->access->portal_categories($this->config['plus_portal_picture_personal'],
			$selected === '' ? array() : array_flip(explode(',', $selected)));
		if (!$categories) { return; }

		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$rate = (bool) $this->access->setting('rate', 1);
		$comments = (bool) $this->access->setting('comment', 1);
		$full = (int) $this->access->setting('fullpic_popup', 0) === 1;
		$this->template->assign_vars(array('U_PORTAL_ALBUM'=>$this->helper->route('phpbb2plus_gallery_index'),
			'S_PORTAL_PICTURE_POPUP'=>$full,
			'PORTAL_PICTURE_SIZE'=>max(1, min(1024, (int) $this->config['plus_portal_picture_size']))));

		$stats = '';
		if ($rate) { $stats .= ',(SELECT AVG(r.points) FROM ' . $this->prefix . 'ratings r WHERE r.pic_id=p.pic_id AND r.points>0) AS rating'; }
		if ($comments) { $stats .= ',(SELECT COUNT(*) FROM ' . $this->prefix . 'comments k WHERE k.pic_id=p.pic_id) AS comments'; }
		$sort = 'p.created_at DESC,p.pic_id DESC';
		if ($this->config['plus_portal_picture_random'])
		{
			$sort = in_array($this->db->get_sql_layer(), array('postgres','sqlite3'), true) ? 'RANDOM()' : 'RAND()';
		}
		$result = $this->db->sql_query_limit('SELECT p.pic_id,p.title,p.description,p.created_at,p.views,p.author_id,p.author_removed,
			p.author_name,u.username,u.user_colour' . $stats . ' FROM ' . $this->prefix . 'pics p
			JOIN ' . $this->prefix . 'cats c ON c.cat_id=p.cat_id LEFT JOIN ' . USERS_TABLE . " u ON u.user_id=p.author_id
			WHERE p.storage_name<>'' AND (p.approved=1 OR c.approval=0) AND " . $this->db->sql_in_set('p.cat_id', $categories) .
			' ORDER BY ' . $sort, $limit);
		while ($pic = $this->db->sql_fetchrow($result))
		{
			$params = array('picture'=>(int) $pic['pic_id']);
			$rating = $rate && $pic['rating'] !== null ?
				number_format((float) $pic['rating'], 2, $this->language->lang('PLUS_ALBUM_DECIMAL'), '') : '';
			$this->template->assign_block_vars('plus_album_pictures', array(
				'TITLE'=>$pic['title'], 'DESCRIPTION'=>$pic['description'], 'AUTHOR'=>$this->byline->display($pic),
				'TIME'=>$this->user->format_date($pic['created_at']), 'VIEWS'=>(int) $pic['views'],
				'S_RATE'=>$rate, 'RATING'=>$rating, 'S_COMMENTS'=>$comments, 'COMMENTS'=>(int) ($pic['comments'] ?? 0),
				'U_PICTURE'=>$full ? $this->helper->route('phpbb2plus_gallery_media', $params + array('size'=>'full')) :
					$this->helper->route('phpbb2plus_gallery_picture', $params),
				'U_THUMB'=>$this->helper->route('phpbb2plus_gallery_media', $params + array('size'=>'small'))));
		}
		$this->db->sql_freeresult($result);
	}
}
