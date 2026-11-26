<?php
namespace phpbb2plus\community\controller;

use phpbb\request\request_interface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class absence
{
	private $state;
	private $user;
	private $auth;
	private $request;
	private $template;
	private $language;
	private $helper;

	public function __construct($state,$user,$auth,$request,$template,$language,$helper)
	{
		$this->state=$state; $this->user=$user; $this->auth=$auth; $this->request=$request;
		$this->template=$template; $this->language=$language; $this->helper=$helper;
	}

	public function own()
	{
		$url=$this->helper->route('phpbb2plus_community_absence',array(),false);
		if (empty($this->user->data['is_registered'])) { login_box($url); }
		$this->show((int)$this->user->data['user_id'],$url,false);
		$response=$this->helper->render('@phpbb2plus_community/absence.html',$this->language->lang('PLUS_AWAY'));
		$response->headers->set('Cache-Control','private, no-store');
		return $response;
	}

	public function show($id,$url,$admin)
	{
		$this->language->add_lang('absence','phpbb2plus/community');
		$row=$this->state->person($id);
		if (!$this->state::account($row)) { throw new NotFoundHttpException(); }
		if (!$this->state->member() || !empty($this->user->data['user_perm_from']) ||
			($admin && (!defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['session_admin']) || !$this->auth->acl_get('a_user') ||
			((int)$row['user_type']===USER_FOUNDER && (int)$this->user->data['user_type']!==USER_FOUNDER)))) { throw new AccessDeniedHttpException(); }
		$allowed=$this->state->can_set($row);
		if (!$allowed && !$row['plus_away']) { throw new AccessDeniedHttpException(); }
		$revision=(string)$row['plus_away_revision'];
		$prefix='plus_away_' . ($admin ? 'admin_' : 'own_') . $id . '_' . $this->user->session_id . '_';
		$error=''; $saved=''; $input=null;
		if ($this->request->server('REQUEST_METHOD')==='POST')
		{
			$submitted=$this->request->variable('revision','',false,request_interface::POST);
			if (!check_form_key($prefix . $submitted)) { throw new AccessDeniedHttpException($this->language->lang('FORM_INVALID')); }
			$action=$this->request->variable('action','',false,request_interface::POST);
			if ($action==='save' && $allowed)
			{
				$input=array('active'=>$this->request->variable('active','',false,request_interface::POST),
					'kind'=>$this->request->variable('kind','',false,request_interface::POST),
					'note'=>$this->request->raw_variable('note',null,request_interface::POST));
			}
			elseif ($action!=='return') { throw new AccessDeniedHttpException(); }
			try
			{
				$this->state->write($id,$submitted,$input,$admin);
				$saved=$action==='return' ? 'PLUS_AWAY_RETURNED' : 'PLUS_AWAY_SAVED';
				$row=$this->state->person($id);
				if (!$admin) { $this->user->data['plus_away']=$row['plus_away']; }
				$revision=(string)$row['plus_away_revision'];
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage()==='NO_AUTH_OPERATION') { throw new AccessDeniedHttpException(); }
				if ($e->getMessage()==='PLUS_AWAY_CHANGED')
				{
					$row=$this->state->person($id);
					if (!$this->state::account($row)) { throw new NotFoundHttpException(); }
					$revision=(string)$row['plus_away_revision'];
					$allowed=$this->state->can_set($row);
					$input=null;
				}
				$error=$this->language->lang($e->getMessage());
			}
		}
		$note=$error!=='' && is_string($input['note'] ?? null) ? $input['note'] : $this->state->note($id);
		// Always use the fresh revision on a redisplayed form. A stale submission never overwrites the intervening state.
		add_form_key($prefix . $revision);
		$this->template->assign_vars(array('U_AWAY_ACTION'=>$url,'PLUS_AWAY_USER'=>$row['username'],'S_AWAY_EDIT'=>$allowed,
			'S_AWAY_ACTIVE'=>(bool)$row['plus_away'],'AWAY_KIND'=>(int)$row['plus_away_mode'],'AWAY_NOTE'=>$note,'AWAY_REVISION'=>$revision,
			'AWAY_ERROR'=>$error,'AWAY_SAVED'=>$saved!=='' ? $this->language->lang($saved) : ''));
	}
}
