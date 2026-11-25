<?php
namespace phpbb2plus\downloads\service;

class field_data
{
	public static function definition($row)
	{
		$source = json_decode($row['source_json'],true);
		return array('type'=>(int) $row['field_type'],'title'=>access::plain($row['title']),
			'description'=>access::plain($row['description']),'pattern'=>$source['regex'] ?? '',
			'options'=>array_map(array(access::class,'plain'),json_decode($row['options_json'],true) ?: array()));
	}

	public static function values($row)
	{
		$values = json_decode($row['values_json'],true);
		if (!is_array($values)) { return array(); }
		$values = array_values(array_filter($values,'is_string'));
		// paFileDB stored each multiselect/checkbox value literally in its serialized
		// list. Single-value fields and native phpBB3 edits were HTML-encoded once.
		if (in_array((int) $row['field_type'],array(4,5),true))
		{
			$source = json_decode($row['source_json'],true);
			if (isset($source['customdata_custom'])) { return $values; }
		}
		return array_map(array(access::class,'plain'),$values);
	}
}
