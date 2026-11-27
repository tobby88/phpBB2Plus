<?php
namespace phpbb2plus\gallery\controller;

use phpbb\request\request_interface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class manage
{
	private $pictures;
	private $helper;
	private $request;
	private $template;
	private $language;
	private $uploads;
	private $breadcrumbs;

	public function __construct($pictures, $helper, $request, $template, $language, $uploads, $breadcrumbs)
	{
		$this->pictures = $pictures;
		$this->helper = $helper;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->uploads = $uploads;
		$this->breadcrumbs = $breadcrumbs;
	}

	public function picture($picture, $mode)
	{
		list($pic, $cat) = $this->pictures->context($picture, $mode);
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$params = array('picture' => (int) $pic['pic_id']);
		$key = 'plus_gallery_' . $mode . '_' . $params['picture'];
		$title = $this->language->lang('PLUS_ALBUM_ACTION_' . strtoupper($mode));
		$problem = '';
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($key)) { throw new AccessDeniedHttpException(); }
			$pic['title'] = htmlspecialchars_decode($this->request->variable('title', '', true, request_interface::POST), ENT_QUOTES);
			$pic['description'] = htmlspecialchars_decode($this->request->variable('description', '', true, request_interface::POST), ENT_QUOTES);
			try
			{
				$category = $this->pictures->change($picture, $mode, $pic['title'], $pic['description'],
					$this->request->variable('destination', 0, false, request_interface::POST));
				$url = $category ? $this->helper->route('phpbb2plus_gallery_category', array('category' => $category), false)
					: $this->helper->route('phpbb2plus_gallery_picture', $params, false);
				return new RedirectResponse($url, 303);
			}
			catch (\DomainException $e) { $problem = $this->language->lang($e->getMessage()); }
		}
		if ($mode === 'move')
		{
			foreach ($this->pictures->destinations($cat) as $to)
			{
				$this->template->assign_block_vars('album_destinations', array('ID' => $to['cat_id'], 'TITLE' => $to['title']));
			}
		}
		$this->breadcrumbs->assign($cat);
		add_form_key($key);
		$this->template->assign_vars(array('ALBUM_ACTION_TITLE' => $title, 'ALBUM_TITLE' => $pic['title'], 'ALBUM_DESCRIPTION' => $pic['description'],
			'ALBUM_LIMIT' => $this->uploads->text_limit(), 'ALBUM_ERROR' => $problem, 'ALBUM_MODE' => $mode,
			'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', $params), 'U_ACTION' => $this->helper->route('phpbb2plus_gallery_manage', $params + array('mode' => $mode))));
		return $this->helper->render('@phpbb2plus_gallery/manage.html', $title, $problem === '' ? 200 : 422);
	}
}
