# IntegraMOD responsive style support for MySQL/MariaDB
#
# Run this file once before installing one of the imported styles. Replace
# the phpbb_ prefix if the board uses a different table prefix.

ALTER TABLE phpbb_themes
  ADD div_class1 varchar(25) DEFAULT NULL,
  ADD div_class2 varchar(25) DEFAULT NULL,
  ADD div_class3 varchar(25) DEFAULT NULL,
  ADD row_class1 varchar(25) DEFAULT NULL,
  ADD row_class2 varchar(25) DEFAULT NULL,
  ADD row_class3 varchar(25) DEFAULT NULL,
  ADD col_class1 varchar(25) DEFAULT NULL,
  ADD col_class2 varchar(25) DEFAULT NULL,
  ADD col_class3 varchar(25) DEFAULT NULL;

ALTER TABLE phpbb_themes_name
  ADD div_class1_name varchar(50) DEFAULT NULL,
  ADD div_class2_name varchar(50) DEFAULT NULL,
  ADD div_class3_name varchar(50) DEFAULT NULL,
  ADD row_class1_name varchar(50) DEFAULT NULL,
  ADD row_class2_name varchar(50) DEFAULT NULL,
  ADD row_class3_name varchar(50) DEFAULT NULL,
  ADD col_class1_name varchar(50) DEFAULT NULL,
  ADD col_class2_name varchar(50) DEFAULT NULL,
  ADD col_class3_name varchar(50) DEFAULT NULL;
