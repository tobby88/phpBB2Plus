<?php
namespace phpbb2plus\downloads\controller;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class recommend
{
	private $mail;
	private $access;
	private $listing;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $config;

	public function __construct($mail,$access,$listing,$user,$language,$template,$helper,$request,$config)
	{
		$this->mail=$mail;
		$this->access=$access;
		$this->listing=$listing;
		$this->user=$user;
		$this->language=$language;
		$this->template=$template;
		$this->helper=$helper;
		$this->request=$request;
		$this->config=$config;
	}

	public function view($file)
	{
		$id=(int) $file;
		$f=$this->access->file($id);
		$registered=!empty($this->user->data['is_registered']);
		$route='phpbb2plus_downloads_recommend';
		$params=array('file'=>$id);
		if (!$this->mail->can($f))
		{
			if (!$registered && empty($this->user->data['is_bot']) && $f['approved'] && $this->config['email_enable'])
			{ login_box($this->helper->route($route,$params,false)); }
			throw new NotFoundHttpException();
		}
		$this->language->add_lang('downloads','phpbb2plus/downloads');
		$this->listing->navigation('',(int) $f['cat_id']);
		$title=access::plain($f['title']);
		$file_url=$this->helper->route('phpbb2plus_downloads_file',$params);
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>utf8_htmlspecialchars($title),'U_VIEW_FORUM'=>$file_url));
		$action=$this->helper->route($route,$params);
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>$this->language->lang('PLUS_DL_RECOMMEND'),'U_VIEW_FORUM'=>$action));
		$form='plus_dl_mail_' . $id;
		$values=array('fname'=>'','femail'=>'','sname'=>'','semail'=>'','subject'=>$title,'message'=>$this->language->lang('PLUS_DL_MAIL_NOTE'));
		$posted=$this->request->server('REQUEST_METHOD')==='POST';
		$nonce=bin2hex(random_bytes(16));
		if ($posted)
		{
			foreach ($values as $key=>$default)
			{
				$value=$this->request->raw_variable($key,'',request_interface::POST);
				$values[$key]=is_string($value) ? $value : '';
			}
			$nonce=$this->request->variable('nonce','',false,request_interface::POST);
			try
			{
				if (!check_form_key($form)) { throw new \DomainException('FORM_INVALID'); }
				$this->mail->send($id,$nonce,$values);
				$response=new RedirectResponse($this->helper->route($route,$params+array('sent'=>$nonce),false),303);
				$response->headers->set('Cache-Control','private, no-store');
				return $response;
			}
			catch (\DomainException $e)
			{
				$this->template->assign_var('DL_ERROR',$this->language->lang($e->getMessage()));
				if (!preg_match('/^[a-f0-9]{32}$/D',$nonce)) { $nonce=bin2hex(random_bytes(16)); }
			}
		}
		if ($registered)
		{
			$values['sname']=html_entity_decode($this->user->data['username'],ENT_QUOTES,'UTF-8');
			$values['semail']=$this->user->data['user_email'];
		}
		foreach ($values as $key=>$value) { $this->template->assign_var('DL_' . strtoupper($key),$value); }
		$this->template->assign_vars(array('U_DL_FORM'=>$action,'U_DL_FILE'=>$file_url,'DL_TITLE'=>$title,'DL_NONCE'=>$nonce,
			'S_DL_REGISTERED'=>$registered,'S_DL_SENT'=>!$posted && $this->mail->delivered($id,$this->request->variable('sent',''))));
		add_form_key($form);
		$response=$this->helper->render('@phpbb2plus_downloads/recommend.html',$this->language->lang('PLUS_DL_RECOMMEND'));
		$response->headers->set('Cache-Control','private, no-store');
		$response->headers->set('Referrer-Policy','no-referrer');
		return $response;
	}
}
