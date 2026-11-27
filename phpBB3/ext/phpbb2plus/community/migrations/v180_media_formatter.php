<?php
namespace phpbb2plus\community\migrations;

class v180_media_formatter extends v173_table_formatter
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v173_table_formatter'); }
}
