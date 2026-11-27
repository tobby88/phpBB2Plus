<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\explicit_acronym;
use phpbb2plus\community\service\formatting_codes;
use phpbb2plus\community\service\legacy_smilie_signs;
use phpbb2plus\community\service\legacy_tables;
use phpbb2plus\community\service\table_formatting;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class legacy_formatting implements EventSubscriberInterface
{
	private $acronyms;
	private $config;
	private $tables;

	public function __construct($db, $container, $config)
	{
		$this->acronyms = new explicit_acronym($db, $container);
		$this->tables = new legacy_tables($db, $container);
		$this->config = $config;
	}

	public static function getSubscribedEvents()
	{
		return array('core.text_formatter_s9e_configure_after' => 'configure',
			'core.modify_text_for_display_before' => 'older_content', 'core.viewtopic_post_rowset_data' => 'topic_post',
			'core.search_modify_post_row' => 'search_post', 'core.ucp_pm_view_message_before' => 'private_message',
			'core.topic_review_modify_post_list' => 'review');
	}

	public function configure($event)
	{
		explicit_acronym::configure($event['configurator']);
		table_formatting::configure($event['configurator']);
		formatting_codes::overflow($event['configurator'], (string) ($this->config['plus_formatting_overflow'] ?? ''));
	}

	private function upgrade($xml)
	{
		$xml = $this->acronyms->for_display(legacy_smilie_signs::upgrade($xml));
		return $this->tables->for_display($xml);
	}

	public function older_content($event)
	{
		if ($event['flags'] & OPTION_FLAG_BBCODE) { $event['text'] = $this->upgrade($event['text']); }
	}

	// Native views infer flags from a legacy bitfield. Use the actual per-message
	// option for previously unrecognised syntax, even if the two fields disagree.
	private function message(&$row, $field, $enabled)
	{
		if (!isset($row[$field]) || $enabled === null) { return; }
		if ($enabled) { $row[$field] = $this->upgrade($row[$field]); }
		elseif (!empty($row['bbcode_bitfield']) && (explicit_acronym::candidate($row[$field]) ||
			legacy_tables::candidate($row[$field]) || stripos($row[$field], '[schild=') !== false))
		{
			$row['bbcode_bitfield'] = '';
		}
	}

	public function topic_post($event)
	{
		$row = $event['rowset_data'];
		$this->message($row, 'post_text', $event['row']['enable_bbcode'] ?? null);
		$event['rowset_data'] = $row;
	}

	public function search_post($event)
	{
		$row = $event['row'];
		$this->message($row, 'post_text', $row['enable_bbcode'] ?? null);
		$event['row'] = $row;
	}

	public function private_message($event)
	{
		$row = $event['message_row'];
		$this->message($row, 'message_text', $row['enable_bbcode'] ?? null);
		$event['message_row'] = $row;
	}

	public function review($event)
	{
		$rows = $event['rowset'];
		foreach ($rows as &$row)
		{
			$this->message($row, 'post_text', $row['enable_bbcode'] ?? null);
		}
		unset($row);
		$event['rowset'] = $rows;
	}
}
