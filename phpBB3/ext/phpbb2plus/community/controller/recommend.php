<?php
namespace phpbb2plus\community\controller;

class recommend
{
	private $form;
	private $request;
	private $template;
	private $helper;
	private $user;
	private $root;
	private $ext;
	public function __construct($form,$request,$template,$helper,$user,$root,$ext)
	{
		$this->form=$form; $this->request=$request; $this->template=$template; $this->helper=$helper; $this->user=$user;
		$this->root=$root; $this->ext=$ext;
	}

	public function board()
	{
		$url=$this->helper->route('phpbb2plus_community_friend',array(),false);
		if (empty($this->user->data['is_registered'])) { login_box($url); }
		$this->user->add_lang(array('memberlist','viewtopic'));
		$this->user->add_lang_ext('phpbb2plus/community','friend');
		$form=clone $this->form;
		$form->for_board($url);
		$form->bind($this->request);
		$error=$form->check_allow();
		if ($error) { trigger_error($error,E_USER_WARNING); }
		if ($this->request->is_set_post('submit'))
		{
			require_once $this->root . 'includes/functions_messenger.' . $this->ext;
			$form->submit(new \messenger(false));
		}
		$form->render($this->template);
		$response=$this->helper->render('memberlist_email.html',$this->user->lang('PLUS_FRIEND_BOARD'));
		$response->headers->set('Cache-Control','private, no-store');
		return $response;
	}
}
