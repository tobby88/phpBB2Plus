<?php
namespace phpbb2plus\gallery\migrations;

class v103_personal extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v102_picture_lifecycle'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_album_cats' => array(
			'label_encoded' => array('BOOL', 0), 'legacy_guest_root' => array('BOOL', 0))));
	}

	public function update_data()
	{
		// The converter resolves legacy single-user groups against the source. Earlier
		// snapshots have no reliable mapping for those groups and default to no grant.
		return array(array('permission.add', array('u_plus_album_personal')));
	}

	// Keep the representation marker when reverting the feature so re-enabling it
	// preserves literal entities. The base migration drops the table on purge.
}
