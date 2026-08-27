# IntegraMOD modern social-profile fields for MySQL/MariaDB
#
# Run this file once on an existing phpBB2 Plus database. Replace the phpbb_
# prefix if the board uses a different table prefix. The legacy AIM, Yahoo and
# MSN fields are intentionally retained for backwards compatibility.

ALTER TABLE phpbb_users
  ADD user_fb varchar(255) DEFAULT NULL,
  ADD user_ig varchar(255) DEFAULT NULL,
  ADD user_pt varchar(255) DEFAULT NULL,
  ADD user_twr varchar(255) DEFAULT NULL,
  ADD user_skp varchar(255) DEFAULT NULL,
  ADD user_tg varchar(255) DEFAULT NULL,
  ADD user_li varchar(255) DEFAULT NULL,
  ADD user_tt varchar(255) DEFAULT NULL,
  ADD user_dc varchar(255) DEFAULT NULL;
