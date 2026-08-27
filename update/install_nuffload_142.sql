# Nuffload 1.4.2 configuration for MySQL/MariaDB
#
# Run this file once after installing or updating phpBB2 Plus. Replace the
# phpbb_ prefix before execution if the board uses a different table prefix.
# The optional Perl/CGI uploader and progress bar are disabled by default;
# enable them in the Album administration only after the CGI path works.

INSERT INTO phpbb_album_config (config_name, config_value) VALUES
  ('path_to_bin', 'cgi-bin/'),
  ('perl_uploader', '0'),
  ('show_progress_bar', '0'),
  ('close_on_finish', '1'),
  ('max_pause', '10'),
  ('simple_format', '0'),
  ('multiple_uploads', '1'),
  ('max_uploads', '10'),
  ('zip_uploads', '1'),
  ('resize_pic', '0'),
  ('resize_width', '600'),
  ('resize_height', '600'),
  ('resize_quality', '70');
