# IntegraMOD cookie-consent and StopForumSpam settings for MySQL/MariaDB
#
# Run this file once on an existing phpBB2 Plus database. Replace the phpbb_
# prefix if the board uses a different table prefix. StopForumSpam is opt-in
# because enabling it sends registration data to an external service.

INSERT INTO phpbb_config (config_name, config_value) VALUES ('cookie_consent_enable', '1');
INSERT INTO phpbb_config (config_name, config_value) VALUES ('sfs_enable', '0');
