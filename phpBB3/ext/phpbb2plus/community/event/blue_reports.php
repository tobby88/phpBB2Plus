<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class blue_reports implements EventSubscriberInterface
{
	private $db;
	private $template;
	private $language;
	private $listed = array();
	private $history_post = 0;

	public function __construct($db, $template, $language)
	{
		$this->db = $db;
		$this->template = $template;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array(
			'core.mcp_front_reports_listing_query_before' => 'select_counts',
			'core.mcp_reports_report_details_query_before' => 'select_counts',
			'core.mcp_front_view_modify_reported_post_row' => 'front',
			'core.mcp_report_template_data' => 'details',
			'core.mcp_reports_modify_post_row' => 'listed',
			'core.mcp_post_template_data' => 'post_history',
			'core.page_footer' => 'complete_blocks');
	}

	public function select_counts($event)
	{
		$sql = $event['sql_ary'];
		$sql['SELECT'] .= ', r.plus_blue_count, r.plus_blue_only';
		$event['sql_ary'] = $sql;
	}

	private function total($count)
	{
		$this->language->add_lang('blue_reports', 'phpbb2plus/community');
		return $this->language->lang('PLUS_BLUE_TOTAL', $count);
	}

	private function preview_row($row, $report)
	{
		$total = $this->total($report['plus_blue_count']);
		if ($report['plus_blue_only'])
		{
			$row['REPORTER'] = $row['REPORTER_FULL'] = $this->language->lang('PLUS_BLUE_UNKNOWN');
			$row['REPORTER_COLOUR'] = $row['U_REPORTER'] = '';
			$row['REPORT_TIME'] = $this->language->lang('PLUS_BLUE_UNKNOWN_TIME');
		}
		$row['REPORT_TIME'] .= ' · ' . $total;
		return $row;
	}

	public function front($event)
	{
		$report = $event['row'];
		if ($report['plus_blue_count'] !== '')
		{
			$event['reported_post_row'] = $this->preview_row($event['reported_post_row'], $report);
		}
	}

	public function details($event)
	{
		$report = $event['report'];
		if ($report['plus_blue_count'] === '') { return; }
		$data = $event['report_template'];
		$note = $this->total($report['plus_blue_count']) . '. ' . $this->language->lang('PLUS_BLUE_SOURCE');
		if ($report['plus_blue_only'])
		{
			$data['REPORTER_FULL'] = $data['REPORTER_NAME'] = $data['REPORT_REASON_TITLE'] = $this->language->lang('PLUS_BLUE_UNKNOWN');
			$data['REPORT_DATE'] = $this->language->lang('PLUS_BLUE_UNKNOWN_TIME');
			$data['U_VIEW_REPORTER_PROFILE'] = $data['U_MCP_WARN_REPORTER'] = $data['U_MCP_REPORTER_NOTES'] = $data['REPORTER_COLOUR'] = '';
			$data['REPORT_TEXT'] = $note;
			$data['POST_PREVIEW'] = $this->language->lang('PLUS_BLUE_NO_SNAPSHOT');
		}
		else
		{
			// A genuinely reported phpBB3 post may already have a reason or message of its own.
			$data['REPORT_TEXT'] = ($data['REPORT_TEXT'] !== '' ? $data['REPORT_TEXT'] : $data['REPORT_REASON_DESCRIPTION']) . '<br />' . $note;
		}
		$event['report_template'] = $data;
	}

	public function listed($event) { $this->listed[] = (int) $event['row']['report_id']; }
	public function post_history($event) { $this->history_post = (int) $event['post_info']['post_id']; }

	public function complete_blocks()
	{
		// The post-details module builds its report history after its last PHP event.
		// Decorate existing, authorized rows only. Read counts in one query for the whole page.
		if ($this->listed)
		{
			$block = 'postrow';
			$where = $this->db->sql_in_set('report_id', $this->listed);
		}
		elseif ($this->history_post && $this->template->retrieve_var('S_SHOW_REPORTS'))
		{
			$block = 'reports';
			$where = 'post_id=' . $this->history_post;
		}
		else { return; }
		$result = $this->db->sql_query('SELECT report_id,plus_blue_count,plus_blue_only FROM ' . REPORTS_TABLE .
			" WHERE $where AND pm_id=0 AND plus_blue_count<>''");
		while ($report = $this->db->sql_fetchrow($result))
		{
			$index = $this->template->find_key_index($block, array('REPORT_ID' => $report['report_id']));
			if ($index === false) { continue; }
			$row = $this->template->retrieve_block_vars($block . '[' . $index . ']', array('REPORT_TIME', 'REPORT_TEXT'));
			$row = $this->preview_row($row, $report);
			if ($block === 'reports' && $report['plus_blue_only'])
			{
				$row['REASON_TITLE'] = $this->language->lang('PLUS_BLUE_UNKNOWN');
				$row['REASON_DESC'] = $this->language->lang('PLUS_BLUE_SOURCE');
			}
			$this->template->alter_block_array($block, $row, $index, 'change');
		}
		$this->db->sql_freeresult($result);
		$this->listed = array();
		$this->history_post = 0;
	}
}
