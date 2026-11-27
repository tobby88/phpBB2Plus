<?php
namespace phpbb2plus\community\group;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class controller
{
	private $groups;
	private $request;
	private $helper;
	private $template;
	private $language;
	private $pagination;
	private $config;
	private $root;
	private $ext;

	public function __construct($groups, $request, $helper, $template, $language, $pagination, $config, $root, $ext)
	{
		$this->groups = $groups; $this->request = $request; $this->helper = $helper; $this->template = $template;
		$this->language = $language; $this->pagination = $pagination; $this->config = $config; $this->root = $root; $this->ext = $ext;
	}

	public function show($group)
	{
		$group = (int) $group;
		$info = $this->groups->managed_group($group);
		if (!$info) { throw new NotFoundHttpException(); }
		$this->language->add_lang(array('ucp', 'acp/groups'));
		$this->language->add_lang('group_leader', 'phpbb2plus/community');
		$start = max(0, $this->request->variable('start', 0));
		$params = array('group' => $group);
		if ($start) { $params['start'] = $start; }
		$url = $this->helper->route('phpbb2plus_community_group_lead', $params, false);
		$error = '';
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key('phpbb2plus/group_lead')) { throw new AccessDeniedHttpException($this->language->lang('FORM_INVALID')); }
			try
			{
				$this->groups->change($group, $this->request->variable('action', ''), $this->request->variable('type', -1),
					$this->request->variable('username', '', true), $this->request->variable('members', array(0)));
				return new RedirectResponse($url, 303, array('Cache-Control'=>'private, no-store'));
			}
			catch (\DomainException $e) { $error = $this->language->lang($e->getMessage()); }
		}
		add_form_key('phpbb2plus/group_lead');
		$per_page = min(100, max(1, (int) $this->config['topics_per_page']));
		list($rows, $total, $start) = $this->groups->roster($group, $start, $per_page);
		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('members', array('ID'=>(int) $row['user_id'], 'NAME'=>htmlspecialchars_decode($row['username'], ENT_QUOTES), 'PENDING'=>(bool) $row['user_pending']));
		}
		$this->pagination->generate_template_pagination($this->helper->route('phpbb2plus_community_group_lead', array('group'=>$group)), 'pagination', 'start', $total, $per_page, $start);
		$this->template->assign_vars(array('GROUP_NAME'=>htmlspecialchars_decode($info['group_name'], ENT_QUOTES), 'GROUP_TYPE'=>(int) $info['group_type'], 'GROUP_ERROR'=>$error,
			'U_GROUP_CHANGE'=>$url, 'U_GROUP_LIST'=>append_sid($this->root . 'ucp.' . $this->ext, 'i=ucp_groups&amp;mode=manage')));
		$response = $this->helper->render('@phpbb2plus_community/group_lead.html', $this->language->lang('UCP_USERGROUPS_MANAGE'), $error ? 422 : 200);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}
}
