# Arcade Mod Plus 2.1.8 database installation for MySQL/MariaDB
#
# Run this file once after installing or updating phpBB2 Plus. Replace the
# phpbb_ prefix before execution if the board uses a different table prefix.
# The installation intentionally contains no games, categories, scores,
# comments, favourites, tournaments, or user activity.

ALTER TABLE phpbb_users
  ADD games_block_pm tinyint(1) NOT NULL DEFAULT 1,
  ADD arcade_banned int(11) NOT NULL DEFAULT 0;

CREATE TABLE phpbb_ina_at_scores (
  game_name varchar(50) DEFAULT NULL,
  player varchar(40) DEFAULT NULL,
  player_id mediumint(8) DEFAULT NULL,
  player_name varchar(25) NOT NULL DEFAULT '',
  score double(14,4) NOT NULL DEFAULT 0.0000,
  date int(11) DEFAULT NULL,
  player_ip varchar(16) DEFAULT '0',
  time_taken int(11) DEFAULT NULL,
  KEY game_name (game_name),
  KEY game_name_player (game_name, player_id),
  KEY date (date)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_banned (
  username varchar(25) DEFAULT NULL,
  user_id mediumint(8) DEFAULT NULL,
  player_ip varchar(16) DEFAULT NULL,
  game varchar(25) DEFAULT NULL,
  score mediumint(8) DEFAULT NULL,
  date varchar(16) DEFAULT NULL,
  KEY user_id (user_id)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_cat (
  cat_id mediumint(8) NOT NULL AUTO_INCREMENT,
  mod_id mediumint(8) DEFAULT NULL,
  cat_name varchar(100) DEFAULT NULL,
  cat_desc text DEFAULT NULL,
  cat_icon varchar(255) DEFAULT NULL,
  special_play smallint(5) NOT NULL DEFAULT 0,
  last_game varchar(50) DEFAULT NULL,
  last_player mediumint(8) DEFAULT NULL,
  last_time int(11) DEFAULT NULL,
  group_required mediumint(8) NOT NULL DEFAULT 0,
  cat_type char(1) NOT NULL DEFAULT 'p',
  cat_parent mediumint(8) DEFAULT NULL,
  cat_order mediumint(8) unsigned NOT NULL DEFAULT 1,
  total_games int(8) unsigned NOT NULL DEFAULT 0,
  total_played bigint(12) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (cat_id)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_comment (
  comment_id int(11) unsigned NOT NULL AUTO_INCREMENT,
  comment_game_name varchar(50) DEFAULT NULL,
  comment_user_id mediumint(8) NOT NULL DEFAULT 0,
  comment_username varchar(32) DEFAULT NULL,
  comment_user_ip varchar(8) NOT NULL DEFAULT '',
  comment_time int(11) unsigned NOT NULL DEFAULT 0,
  comment_text text DEFAULT NULL,
  comment_edit_time int(11) unsigned DEFAULT NULL,
  comment_edit_count smallint(5) unsigned NOT NULL DEFAULT 0,
  comment_edit_user_id mediumint(8) DEFAULT NULL,
  game_name varchar(50) DEFAULT NULL,
  PRIMARY KEY (comment_id),
  KEY comment_game_name (comment_game_name),
  KEY comment_user_id (comment_user_id)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_data (
  config_name varchar(255) NOT NULL DEFAULT '0',
  config_value varchar(255) NOT NULL DEFAULT '0',
  UNIQUE KEY config_name (config_name)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_fav (
  user_id mediumint(8) NOT NULL DEFAULT 0,
  fav_game_id mediumint(9) DEFAULT NULL,
  fav_game_name varchar(50) DEFAULT NULL,
  KEY fav_game_id (fav_game_id),
  KEY fav_game_name (fav_game_name)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_games (
  game_id mediumint(9) NOT NULL AUTO_INCREMENT,
  game_name varchar(50) DEFAULT NULL,
  game_path varchar(255) DEFAULT NULL,
  game_desc varchar(255) DEFAULT NULL,
  game_charge int(11) unsigned DEFAULT 0,
  game_reward int(11) unsigned NOT NULL DEFAULT 0,
  game_bonus smallint(5) unsigned DEFAULT 0,
  game_use_gl tinyint(3) unsigned DEFAULT 0,
  game_flash tinyint(1) unsigned NOT NULL DEFAULT 0,
  game_show_score tinyint(1) NOT NULL DEFAULT 1,
  win_width smallint(6) NOT NULL DEFAULT 0,
  win_height smallint(6) NOT NULL DEFAULT 0,
  highscore_limit varchar(255) DEFAULT NULL,
  reverse_list tinyint(1) NOT NULL DEFAULT 0,
  played int(10) unsigned NOT NULL DEFAULT 0,
  instructions text DEFAULT NULL,
  game_avail tinyint(1) DEFAULT 1,
  allow_guest tinyint(1) DEFAULT 0,
  image_path varchar(255) DEFAULT '.gif',
  cat_id mediumint(8) DEFAULT -1,
  at_highscore_limit smallint(5) DEFAULT 0,
  at_game_bonus smallint(5) DEFAULT 0,
  score_type smallint(1) DEFAULT NULL,
  game_autosize smallint(1) DEFAULT NULL,
  date_added int(11) DEFAULT 0,
  rank_required int(11) NOT NULL DEFAULT 0,
  level_required tinyint(4) NOT NULL DEFAULT 0,
  group_required mediumint(8) NOT NULL DEFAULT 0,
  game_control int(1) NOT NULL DEFAULT 0,
  highscore_id mediumint(8) NOT NULL DEFAULT 0,
  at_highscore_id mediumint(8) NOT NULL DEFAULT 0,
  PRIMARY KEY (game_id),
  KEY game_name (game_name)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_highscore (
  highscore_id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  highscore_year year(4) NOT NULL DEFAULT 0000,
  highscore_mon tinyint(2) unsigned NOT NULL DEFAULT 0,
  highscore_game varchar(50) NOT NULL DEFAULT '',
  highscore_player varchar(40) NOT NULL DEFAULT '',
  highscore_score double(12,4) unsigned NOT NULL DEFAULT 0.0000,
  highscore_date int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (highscore_id),
  KEY highscore_game (highscore_game)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_log (
  record_no mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  user_id mediumint(8) NOT NULL DEFAULT -1,
  name text DEFAULT NULL,
  value text DEFAULT NULL,
  date int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (record_no)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_pms (
  to_id mediumint(8) NOT NULL DEFAULT 0,
  from_id mediumint(8) NOT NULL DEFAULT 0,
  last_sent int(11) NOT NULL DEFAULT 0,
  total_sent mediumint(8) DEFAULT NULL,
  code tinyint(4) DEFAULT NULL,
  KEY to_id (to_id)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_rate (
  rate_game_name varchar(50) DEFAULT NULL,
  rate_user_id mediumint(8) NOT NULL DEFAULT 0,
  rate_user_ip varchar(8) NOT NULL DEFAULT '',
  rate_point tinyint(3) unsigned NOT NULL DEFAULT 0,
  KEY rate_game_name (rate_game_name)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_scores (
  game_name varchar(50) DEFAULT NULL,
  gameData text DEFAULT NULL,
  player varchar(40) DEFAULT NULL,
  player_id mediumint(8) DEFAULT NULL,
  score double(14,4) NOT NULL DEFAULT 0.0000,
  date int(11) DEFAULT NULL,
  player_ip varchar(16) DEFAULT '0',
  time_taken int(11) DEFAULT NULL,
  KEY game_name (game_name),
  KEY game_name_player (game_name, player_id),
  KEY date (date)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_sessions (
  session_id varchar(32) DEFAULT NULL,
  user_id mediumint(8) NOT NULL DEFAULT 0,
  start_time int(11) NOT NULL DEFAULT 0,
  session_ip varchar(8) NOT NULL DEFAULT '',
  page int(1) NOT NULL DEFAULT 0,
  game_name varchar(50) DEFAULT NULL,
  user_ip varchar(16) DEFAULT '0',
  ip_name varchar(255) DEFAULT NULL,
  arcade_hash varchar(33) DEFAULT NULL,
  user_win varchar(25) DEFAULT 'NORM',
  tour_id mediumint(5) DEFAULT NULL,
  randchar1 int(4) DEFAULT NULL,
  randchar2 int(4) DEFAULT NULL,
  KEY session_id (session_id),
  KEY arcade_hash (arcade_hash)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_tour (
  tour_id mediumint(5) NOT NULL AUTO_INCREMENT,
  tour_name varchar(25) DEFAULT NULL,
  tour_desc text DEFAULT NULL,
  tour_max_players int(4) NOT NULL DEFAULT 0,
  tour_player_turns int(4) NOT NULL DEFAULT 0,
  block_plays int(1) DEFAULT NULL,
  tour_active int(1) NOT NULL DEFAULT 0,
  start_id mediumint(8) NOT NULL DEFAULT 0,
  start_date int(11) NOT NULL DEFAULT 0,
  end_date int(11) DEFAULT NULL,
  length int(11) DEFAULT NULL,
  end_type int(1) DEFAULT NULL,
  champion mediumint(8) DEFAULT NULL,
  PRIMARY KEY (tour_id)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_tour_data (
  tour_id mediumint(5) NOT NULL DEFAULT 0,
  game_name varchar(50) NOT NULL DEFAULT '',
  top_score double(12,4) DEFAULT NULL,
  top_player mediumint(11) DEFAULT NULL,
  PRIMARY KEY (tour_id, game_name)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_tour_invite (
  tour_id mediumint(5) NOT NULL DEFAULT 0,
  user_id mediumint(8) NOT NULL DEFAULT 0,
  PRIMARY KEY (tour_id, user_id)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_tour_play (
  tour_id mediumint(5) NOT NULL DEFAULT 0,
  user_id mediumint(8) NOT NULL DEFAULT 0,
  last_played_time int(11) NOT NULL DEFAULT 0,
  last_played_game varchar(50) DEFAULT NULL,
  gamedata text DEFAULT NULL,
  PRIMARY KEY (tour_id, user_id)
) ENGINE=MyISAM;

CREATE TABLE phpbb_ina_user_data (
  user_id mediumint(8) NOT NULL DEFAULT 0,
  last_played varchar(50) DEFAULT NULL,
  last_played_date int(11) DEFAULT NULL,
  first_places mediumint(9) DEFAULT 0,
  first_list text DEFAULT NULL,
  second_places mediumint(9) DEFAULT NULL,
  second_list text DEFAULT NULL,
  third_places mediumint(9) DEFAULT NULL,
  third_list text DEFAULT NULL,
  last_won_date int(11) DEFAULT NULL,
  at_first_places mediumint(9) DEFAULT 0,
  at_first_list text DEFAULT NULL,
  at_second_places mediumint(9) DEFAULT NULL,
  at_second_list text DEFAULT NULL,
  at_third_places mediumint(9) DEFAULT NULL,
  at_third_list text DEFAULT NULL,
  user_game_time int(11) DEFAULT 0,
  KEY user_id (user_id)
) ENGINE=MyISAM;

INSERT INTO phpbb_ina_data (config_name, config_value) VALUES
  ('default_reward_dbfield', 'user_points'),
  ('default_cash', 'user_cash'),
  ('use_rewards_mod', '0'),
  ('use_cash_system', '0'),
  ('report_cheater', '1'),
  ('warn_cheater', '1'),
  ('use_point_system', '0'),
  ('use_gamelib', '0'),
  ('games_path', 'games/'),
  ('gamelib_path', '0'),
  ('use_gk_shop', '0'),
  ('use_allowance_system', '0'),
  ('games_per_page', '0'),
  ('games_default_img', 'templates/fisubsilversh/images/games.gif'),
  ('games_default_txt', 'No games have been installed.'),
  ('games_default_id', '0'),
  ('games_tournament_mode', '0'),
  ('games_offline', '1'),
  ('games_cheat_mode', '1'),
  ('games_guest_highscore', '0'),
  ('games_auto_size', '1'),
  ('games_at_highscore', '1'),
  ('games_show_stats', '1'),
  ('games_image_width', '50'),
  ('games_image_height', '50'),
  ('games_per_admin_page', '0'),
  ('games_cat_image_width', '80'),
  ('games_cat_image_height', '80'),
  ('games_tournament_max', '10'),
  ('games_tournament_games', '6'),
  ('games_tournament_players', '12'),
  ('games_moderators_mode', '1'),
  ('games_posts_required', '0'),
  ('games_use_pms', '1'),
  ('games_total_top', '10'),
  ('games_new_games', '1'),
  ('games_cat_zero', 'Show all games'),
  ('games_use_comments', '0'),
  ('games_use_rating', '0'),
  ('games_show_played', '1'),
  ('games_show_all', '1'),
  ('games_no_guests', '1'),
  ('games_rate', '1'),
  ('games_comments', '1'),
  ('games_mod_ban_users', '1'),
  ('games_comment_size', '256'),
  ('games_rank_required', '0'),
  ('games_level_required', '0'),
  ('games_pm_highscore', '1'),
  ('games_pm_at_highscore', '1'),
  ('games_pm_comment', '1'),
  ('games_pm_new', '0'),
  ('highscore_start_year', '2006'),
  ('highscore_start_mon', '12'),
  ('default_sort', 'date_added'),
  ('default_sort_order', 'DESC'),
  ('games_show_fav', '1'),
  ('games_new_for', '604800'),
  ('use_cache', '0'),
  ('config_cache', '60'),
  ('categories_cache', '60'),
  ('games_cache', '60'),
  ('games_show_mhm', '1'),
  ('games_tournament_user', '1'),
  ('version', '2.1.8'),
  ('games_default_rate', '5'),
  ('highscore_cache', '60'),
  ('at_highscore_cache', '60'),
  ('games_use_log', '1');
