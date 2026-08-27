# DB Maintenance Mod 1.3.8 configuration for MySQL/MariaDB
#
# Run this file once after installing the module. Replace the phpbb_ prefix
# before execution if the board uses a different table prefix. INSERT IGNORE
# preserves any settings that already exist on an upgraded board.

INSERT IGNORE INTO phpbb_config (config_name, config_value) VALUES
  ('dbmtnc_rebuild_end', '0'),
  ('dbmtnc_rebuild_pos', '-1'),
  ('dbmtnc_rebuildcfg_maxmemory', '500'),
  ('dbmtnc_rebuildcfg_minposts', '3'),
  ('dbmtnc_rebuildcfg_php3only', '0'),
  ('dbmtnc_rebuildcfg_php3pps', '1'),
  ('dbmtnc_rebuildcfg_php4pps', '8'),
  ('dbmtnc_rebuildcfg_timelimit', '240'),
  ('dbmtnc_rebuildcfg_timeoverwrite', '0'),
  ('dbmtnc_disallow_postcounter', '0'),
  ('dbmtnc_disallow_rebuild', '0');
