<?php
namespace phpbb2plus\arcade\controller;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\comments;
use phpbb2plus\arcade\service\score;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class discussion
{
	private $comments;
	private $catalog;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;
	private $config;
	private $names;

	public function __construct($comments,$catalog,$user,$language,$template,$helper,$request,$pagination,$config,$names = null)
	{
		$this->comments = $comments;
		$this->catalog = $catalog;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->config = $config;
		$this->names = $names;
	}

	private function header($id)
	{
		$this->language->add_lang('arcade','phpbb2plus/arcade');
		$game = $this->comments->game($id);
		$title = catalog::plain($game['game_desc'] ?: $game['game_name']);
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>$this->language->lang('PLUS_ARC'),
			'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_arcade_index')));
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>utf8_htmlspecialchars($title),
			'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_arcade_game',array('game'=>(int) $id))));
		$this->template->assign_vars(array('ARC_TITLE'=>$title,'ARC_COMMENT_LIMIT'=>$game['comment_limit']));
		return $game;
	}

	public function legacy()
	{
		$game = $this->request->variable('game', 0);
		$comment = $this->request->variable('comment', 0);
		$view = $this->request->variable('view', 'show');
		$start = max(0, $this->request->variable('start', 0));
		$order = $this->request->variable('order', '') === 'desc' ? 'desc' : '';
		if ($game < 0 || $comment < 0 || (!$game && !$comment) || !in_array($view, array('show','add','edit','delete'), true)
			|| (!$comment && in_array($view, array('edit','delete'), true))) { throw new NotFoundHttpException(); }
		if (empty($this->user->data['is_registered']))
		{
			$return = array_filter(array('game' => $game, 'comment' => $comment, 'view' => $view, 'order' => $order, 'start' => $start));
			login_box($this->helper->route('phpbb2plus_arcade_legacy_comments', $return, false));
		}
		list($target, $row) = $this->comments->legacy_target($game, $comment);
		$params = array('game' => (int) $target['game_id']);
		if ($view === 'edit' || $view === 'delete')
		{
			if (!$row || !$this->comments->manage($target, $row)) { throw new NotFoundHttpException(); }
			$url = $this->helper->route('phpbb2plus_arcade_change_comment', $params + array('comment' => $comment, 'action' => $view), false);
		}
		else
		{
			$params += array_filter(array('focus' => $comment, 'order' => $order, 'start' => $start));
			$anchor = $view === 'add' ? '#arcade-add-comment' : ($comment ? '#comment-' . $comment : '');
			$url = $this->helper->route('phpbb2plus_arcade_comments', $params, false) . $anchor;
		}
		return new RedirectResponse($url, 302, array('Cache-Control' => 'private, no-store'));
	}

	public function listing($game)
	{
		$g = $this->header($game);
		$params = array('game'=>(int) $game);
		$desc = $this->request->variable('order','asc') === 'desc';
		$size = max(1,min(1000,(int) $this->config['posts_per_page']));
		$total = $this->comments->count($game);
		$start = max(0,$this->request->variable('start',0));
		$focus = $this->request->variable('focus',0);
		if ($focus > 0)
		{
			$this->comments->entry($game,$focus);
			$start = intdiv($this->comments->count($game,$focus,$desc),$size)*$size;
		}
		$start = $this->pagination->validate_start($start,$size,$total);
		$url = $this->helper->route('phpbb2plus_arcade_comments',$params + array('order'=>$desc ? 'desc' : 'asc'));
		$this->pagination->generate_template_pagination($url,'pagination','start',$total,$size,$start);
		$hidden = $this->names ? $this->names->hidden() : empty($this->user->data['is_registered']);
		foreach ($this->comments->page($g,$desc,$size,$start) as $row)
		{
			$id = (int) $row['comment_id'];
			$manage = $this->comments->manage($g,$row);
			$route = $params + array('comment'=>$id);
			$row['display_name'] = $row['comment_username'];
			$editor = $row['edited_by'] === null ? $this->language->lang('PLUS_ARC_FORMER') :
				get_username_string('full',$row['editor_id'],$row['edited_by'],$row['edit_colour']);
			$ip = '';
			if ($this->catalog->admin())
			{
				$ip = $row['author_ip'];
				if ($ip === '' && preg_match('/^[0-9a-f]{8}$/iD',(string) $row['comment_user_ip']) && $row['comment_user_ip'] !== '00000000')
				{ $ip = inet_ntop(hex2bin($row['comment_user_ip'])); }
			}
			$elapsed = max(0,(int) $row['time_taken']);
			$this->template->assign_block_vars('arc_comments',array('ID'=>$id,'PLAYER'=>catalog::player_name($row,$this->language,$hidden),
				'DATE'=>$this->user->format_date((int) $row['comment_time']),'BODY'=>comments::render($row['comment_text']),
				'EDIT_COUNT'=>(int) $row['comment_edit_count'],'EDITOR'=>$editor,'EDIT_DATE'=>$this->user->format_date((int) $row['comment_edit_time']),
				'HAS_SCORE'=>$row['score_units'] !== null,'SCORE'=>score::display($row['score_units'] ?? 0,$this->language->lang('PLUS_ARC_DECIMAL')),
				'DURATION'=>sprintf('%d:%02d:%02d',intdiv($elapsed,3600),intdiv($elapsed%3600,60),$elapsed%60),'IP'=>$ip,
				'U_EDIT'=>$manage ? $this->helper->route('phpbb2plus_arcade_change_comment',$route + array('action'=>'edit')) : '',
				'U_DELETE'=>$manage ? $this->helper->route('phpbb2plus_arcade_change_comment',$route + array('action'=>'delete')) : ''));
		}
		$this->template->assign_vars(array('U_ARC_SORT'=>$this->helper->route('phpbb2plus_arcade_comments',$params + array('order'=>$desc ? 'asc' : 'desc')),
			'ARC_DESC'=>$desc,'U_ARC_GAME'=>$this->helper->route('phpbb2plus_arcade_game',$params)));
		if ($this->comments->recently_played($g))
		{
			add_form_key('plus_arc_comment_add_' . (int) $game . '_0');
			$this->template->assign_vars(array('ARC_COMMENT_NONCE'=>bin2hex(random_bytes(16)),
				'U_ARC_ADD'=>$this->helper->route('phpbb2plus_arcade_add_comment',$params)));
		}
		return $this->helper->render('@phpbb2plus_arcade/comments.html',$this->language->lang('PLUS_ARC_COMMENTS'));
	}

	public function change($game,$action,$comment)
	{
		$g = $this->header($game);
		$params = array('game'=>(int) $game);
		$form = 'plus_arc_comment_' . $action . '_' . (int) $game . '_' . (int) $comment;
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($form)) { throw new AccessDeniedHttpException(); }
			$raw = catalog::plain($this->request->variable('body','',true,request_interface::POST));
			try
			{
				$id = $this->comments->write($game,$action,$comment,$raw,$this->request->variable('receipt','',false,request_interface::POST),
					$this->request->variable('revision','',false,request_interface::POST));
			}
			catch (\DomainException $e)
			{
				return $this->helper->message($e->getMessage(),array($g['comment_limit']),'INFORMATION',$e->getMessage() === 'PLUS_ARC_COMMENT_CHANGED' ? 409 : 422);
			}
			if ($id) { $params['focus'] = $id; }
			return new RedirectResponse($this->helper->route('phpbb2plus_arcade_comments',$params,false) . ($id ? '#comment-' . $id : ''),303);
		}
		$row = $this->comments->entry($game,$comment);
		if (!$this->comments->manage($g,$row)) { throw new NotFoundHttpException(); }
		add_form_key($form);
		$this->template->assign_vars(array('ARC_DELETE'=>$action === 'delete','ARC_BODY'=>catalog::plain($row['comment_text']),
			'ARC_REVISION'=>comments::revision($row),'U_ARC_BACK'=>$this->helper->route('phpbb2plus_arcade_comments',$params + array('focus'=>(int) $comment)),
			'U_ARC_CHANGE'=>$this->helper->route('phpbb2plus_arcade_change_comment',$params + array('comment'=>(int) $comment,'action'=>$action))));
		return $this->helper->render('@phpbb2plus_arcade/comment_form.html',$this->language->lang($action === 'delete' ? 'PLUS_ARC_COMMENT_DELETE' : 'PLUS_ARC_COMMENT_EDIT'));
	}
}
