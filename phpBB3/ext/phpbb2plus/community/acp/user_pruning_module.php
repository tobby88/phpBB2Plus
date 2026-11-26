<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\service\account_pruning;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class user_pruning_module
{
	public $u_action;
	public $page_title = 'ACP_PLUS_USER_PRUNING';
	public $tpl_name = 'acp_plus_user_pruning';

	private function show_rows($rows)
	{
		global $user, $template;
		foreach ($rows as $id => $row)
		{
			$last = max((int) $row['user_lastvisit'], (int) $row['user_last_active']);
			$template->assign_block_vars('prune_accounts', array(
				'ID' => $id,
				'NAME' => htmlspecialchars_decode($row['username'], ENT_QUOTES),
				'JOINED' => $user->format_date($row['user_regdate']),
				'LAST' => $last ? $user->format_date($last) : '',
				'POSTS' => (int) $row['user_posts'],
			));
		}
	}

	public function main($module, $mode)
	{
		global $auth, $config, $db, $phpbb_container, $phpbb_root_path, $phpEx, $request, $template, $user;
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_plus_user_pruning', 'phpbb2plus/community');
		$pruning = new account_pruning($db, $auth, $user, $config, $phpbb_container->get('log'), $phpbb_root_path, $phpEx);
		$key = 'plus_account_pruning';
		$template->assign_var('U_PRUNE_HOME', $this->u_action);
		try
		{
			$pruning->actor();
			if ($request->server('REQUEST_METHOD') === 'POST')
			{
				if (!check_form_key($key)) { throw new \DomainException('FORM_INVALID'); }
				$step = $request->variable('step', '', false, request_interface::POST);
				$ticket = $request->variable('ticket', '', false, request_interface::POST);
				if ($step === 'review')
				{
					list($policy, $offered) = $pruning->open('offer', $ticket);
					$ids = $request->variable('accounts', array(0), false, request_interface::POST);
					$rows = $pruning->confirmation($policy, $offered, $ids);
					$notify = $config['email_enable'] && $request->variable('notify', 0, false, request_interface::POST) === 1;
					$this->show_rows($rows);
					$template->assign_vars(array(
						'S_PRUNE_REVIEW' => true,
						'S_PRUNE_NOTIFY' => $notify,
						'PRUNE_SKIPPED' => count(array_unique($ids)) - count($rows),
						'PRUNE_RULE' => $language->lang('PLUS_PRUNE_RULE_' . $policy['rule']),
						'PRUNE_DAYS' => $policy['days'],
						'PRUNE_TICKET' => $pruning->ticket('confirm', $policy, $pruning->selection($rows), $notify),
					));
				}
				elseif ($step === 'delete' && $request->variable('confirm_delete', 0, false, request_interface::POST) === 1)
				{
					list($policy, $selected, $notify) = $pruning->open('confirm', $ticket);
					if (!$selected) { throw new \DomainException('PLUS_PRUNE_SELECT'); }
					list($removed, $skipped, $failed, $interrupted) = $pruning->apply($policy, $selected, $notify);
					foreach ($removed as $id => $name)
					{
						$template->assign_block_vars('removed', array('ID' => $id, 'NAME' => htmlspecialchars_decode($name, ENT_QUOTES)));
					}
					$template->assign_vars(array('S_PRUNE_RESULT' => true, 'PRUNE_REMOVED' => count($removed), 'PRUNE_SKIPPED' => $skipped,
						'PRUNE_MAIL_FAILED' => $failed, 'S_PRUNE_INTERRUPTED' => $interrupted));
				}
				else { throw new \DomainException('FORM_INVALID'); }
			}
			elseif ($request->is_set('rule', request_interface::GET))
			{
				$rule = $request->variable('rule', '', false, request_interface::GET);
				$days = $request->variable('days', '', false, request_interface::GET);
				if (!ctype_digit($rule) || !ctype_digit($days)) { throw new \DomainException('FORM_INVALID'); }
				$policy = $pruning->policy((int) $rule, (int) $days, time());
				list($rows, $next) = $pruning->page($policy, $request->variable('after', 0, false, request_interface::GET));
				$this->show_rows($rows);
				$template->assign_vars(array('S_PRUNE_OFFER' => true, 'S_PRUNE_CAN_NOTIFY' => (bool) $config['email_enable'],
					'PRUNE_TICKET' => $pruning->ticket('offer', $policy, $pruning->selection($rows)),
					'PRUNE_RULE' => $language->lang('PLUS_PRUNE_RULE_' . $policy['rule']), 'PRUNE_DAYS' => $policy['days'],
					'U_PRUNE_NEXT' => $next ? $this->u_action . '&amp;rule=' . $policy['rule'] . '&amp;days=' . $policy['days'] . '&amp;after=' . $next : ''));
			}
			else
			{
				// Native ACP links carry the module, mode and confirmed session in the query.
				// A GET form must include these fields when it builds its own query string.
				$url = explode('?', htmlspecialchars_decode($this->u_action, ENT_QUOTES), 2);
				parse_str($url[1] ?? '', $context);
				$template->assign_vars(array('U_PRUNE_FILTER' => $url[0], 'PRUNE_CONTEXT' => build_hidden_fields($context)));
				foreach (account_pruning::DEFAULT_DAYS as $rule => $days)
				{
					$template->assign_block_vars('prune_rules', array('ID' => $rule, 'DAYS' => $days, 'LABEL' => $language->lang('PLUS_PRUNE_RULE_' . $rule)));
				}
			}
			add_form_key($key);
		}
		catch (AccessDeniedHttpException $e)
		{
			send_status_line(403, 'Forbidden');
			trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
		}
		catch (\DomainException $e)
		{
			$template->assign_var('PRUNE_ERROR', $language->lang($e->getMessage()));
		}
	}
}
