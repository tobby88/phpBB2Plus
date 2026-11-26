<?php
namespace phpbb2plus\gallery\controller;

use phpbb\request\request_interface;
use phpbb2plus\gallery\service\upload_archive;
use Symfony\Component\HttpFoundation\JsonResponse;

class upload
{
	private $uploads;
	private $request;
	private $template;
	private $helper;
	private $language;
	private $user;

	public function __construct($uploads, $request, $template, $helper, $language, $user)
	{
		$this->uploads = $uploads;
		$this->request = $request;
		$this->template = $template;
		$this->helper = $helper;
		$this->language = $language;
		$this->user = $user;
	}

	public function form($category)
	{
		$cat = $this->uploads->destination($category);
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		if ($this->uploads->limit() < 1) { return $this->helper->message('PLUS_ALBUM_NO_FILE_SLOTS', array(), 'INFORMATION', 503); }
		$types = $this->uploads->allowed_types();
		if (!$types)
		{
			if ($this->request->is_ajax()) { return new JsonResponse(array('message' => $this->language->lang('PLUS_ALBUM_NO_FORMATS')), 422); }
			return $this->helper->message('PLUS_ALBUM_NO_FORMATS', array(), 'INFORMATION', $this->request->server('REQUEST_METHOD') === 'POST' ? 422 : 200);
		}
		$params = array('category' => (int) $category);
		$target = $this->helper->route('phpbb2plus_gallery_category', $params);
		$key = 'plus_gallery_upload_' . (int) $category;
		$fields = array();
		foreach (array('title','description','guest_name') as $name)
		{
			$fields[$name] = htmlspecialchars_decode($this->request->variable($name, '', true, request_interface::POST), ENT_QUOTES);
		}
		$error = '';
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			try
			{
				if (!check_form_key($key)) { throw new \DomainException('FORM_INVALID'); }
				$pending = $this->uploads->publish($category, $this->request->variable('batch', '', false, request_interface::POST),
					$this->request->raw_variable('photos', array(), request_interface::FILES),
					$this->request->variable('file_count', 0, false, request_interface::POST), $fields['title'], $fields['description'], $fields['guest_name'],
					$this->request->raw_variable('previews', array(), request_interface::FILES));
				if ($this->request->is_ajax())
				{
					return new JsonResponse(array('location' => htmlspecialchars_decode($target, ENT_QUOTES), 'message' => $this->language->lang($pending ? 'PLUS_ALBUM_PENDING' : 'PLUS_ALBUM_SAVED')));
				}
				$this->template->assign_vars(array('S_ALBUM_SAVED' => true, 'S_ALBUM_PENDING' => $pending, 'U_CATEGORY' => $target, 'ALBUM_CATEGORY' => $cat['title']));
				return $this->helper->render('@phpbb2plus_gallery/upload.html', $this->language->lang('PLUS_ALBUM_UPLOAD'));
			}
			catch (\DomainException $e)
			{
				$error = $this->language->lang($e->getMessage());
				if ($this->request->is_ajax()) { return new JsonResponse(array('message' => $error), 422); }
			}
		}
		add_form_key($key);
		$limits = $this->uploads->image_limits();
		$formats = array_map(function ($mime) { return strtoupper(substr($mime, 6)); }, $types);
		$this->template->assign_vars(array('ALBUM_ERROR' => $error, 'ALBUM_BATCH' => bin2hex(random_bytes(16)),
			'ALBUM_TITLE' => $fields['title'], 'ALBUM_DESCRIPTION' => $fields['description'], 'ALBUM_GUEST' => $fields['guest_name'],
			'ALBUM_CATEGORY' => $cat['title'], 'ALBUM_COUNT' => $this->uploads->limit(), 'ALBUM_DESC_LIMIT' => $this->uploads->text_limit(),
			'ALBUM_ACCEPT' => implode(',', $types),
			'ALBUM_FILE_HINT' => $this->language->lang('PLUS_ALBUM_FILE_HINT', implode(', ', $formats), $limits['bytes'], $limits['width'], $limits['height']),
			'S_ZIP' => $this->uploads->zip_enabled() && upload_archive::available(), 'ZIP_LIMIT' => $this->uploads->archive_limit(),
			'ZIP_BATCH_LIMIT' => min($this->uploads->picture_limit(), $this->uploads->limit() * $this->uploads->archive_limit()),
			'S_ZIP_UNAVAILABLE' => $this->uploads->zip_enabled() && !upload_archive::available(),
			'S_MANUAL_PREVIEWS' => $this->uploads->manual(), 'PREVIEW_BOUND' => $this->uploads->preview_bound(), 'S_RESIZE' => $this->uploads->resizing(),
			'S_ALBUM_GUEST' => empty($this->user->data['is_registered']), 'U_ALBUM_UPLOAD' => $this->helper->route('phpbb2plus_gallery_upload', $params),
			'U_CATEGORY' => $target) + $this->uploads->progress_options());
		return $this->helper->render('@phpbb2plus_gallery/upload.html', $this->language->lang('PLUS_ALBUM_UPLOAD'), $error === '' ? 200 : 422);
	}
}
