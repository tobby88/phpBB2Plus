<?php
namespace phpbb2plus\knowledgebase\migrations;

class v102_delivery extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\knowledgebase\migrations\v101_editor'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_kb_';
		return array('add_columns' => array($p . 'articles' => array('discussion_hash' => array('VCHAR:64', ''), 'intro_post' => array('UINT', 0)),
			$this->table_prefix . 'posts' => array('plus_kb_article' => array('UINT', 0), 'plus_kb_digest' => array('VCHAR:64', ''))),
			'add_index' => array($this->table_prefix . 'posts' => array('plus_kb_receipt' => array('plus_kb_article','post_id'))),
			'add_tables' => array($p . 'mail' => array('COLUMNS' => array('article_id' => array('UINT', 0), 'revision' => array('UINT:10', 1),
				'queued_at' => array('TIMESTAMP', 0), 'lease_until' => array('TIMESTAMP', 0), 'claim_token' => array('VCHAR:32', '')),
				'PRIMARY_KEY' => array('article_id', 'revision'), 'KEYS' => array('ready' => array('INDEX', array('lease_until','queued_at'))))));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_kb_mail_time', 0, true)), array('config.add', array('plus_kb_editor_lock', '0', true)));
	}

	public function revert_schema()
	{
		return array('drop_keys' => array($this->table_prefix . 'posts' => array('plus_kb_receipt')),
			'drop_columns' => array($this->table_prefix . 'plus_kb_articles' => array('discussion_hash','intro_post'),
				$this->table_prefix . 'posts' => array('plus_kb_article','plus_kb_digest')), 'drop_tables' => array($this->table_prefix . 'plus_kb_mail'));
	}
}
