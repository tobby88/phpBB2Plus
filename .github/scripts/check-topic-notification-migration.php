<?php
namespace TopicNotificationMigrationFixture;
function migration_check($ok,$message) { if(!$ok) { throw new \RuntimeException($message); } }
function update_table_exists($connection,$database,$table) { return $GLOBALS['fixture_table_exists']; }
function update_column_exists($connection,$database,$table,$column) { return in_array($column,$GLOBALS['fixture_columns'],true); }
function update_quote_identifier($value) { return '`'.str_replace('`','``',$value).'`'; }
$root=dirname(dirname(__DIR__));
$source=file_get_contents($root.'/update/update_from_153a.php');
$start=strpos($source,'function update_queue_column('); $end=strpos($source,'function update_queue_default(',$start);
migration_check($start!==false && $end>$start,'Locate actual additive migration planner');
eval('namespace TopicNotificationMigrationFixture;'.substr($source,$start,$end-$start));
migration_check(strpos($source,"update_queue_topic_notification_columns(\$operations, \$connection, \$dbname, \$table_prefix . 'topics_watch');")!==false,'Updater invokes the planner');
$fixture_table_exists=true; $fixture_columns=array('topic_id','user_id','notify_status'); $operations=array();
update_queue_topic_notification_columns($operations,null,'fixture','custom_topics_watch');
migration_check($operations===array("ALTER TABLE `custom_topics_watch` ADD `notify_claim` CHAR(32) NOT NULL DEFAULT ''",'ALTER TABLE `custom_topics_watch` ADD `notify_claimed_at` INT(10) UNSIGNED NOT NULL DEFAULT 0'),'Exactly two additive defaulted columns, no deletes or data rewrites');
$fixture_columns[]='notify_claim'; $operations=array(); update_queue_topic_notification_columns($operations,null,'fixture','custom_topics_watch');
migration_check(count($operations)===1 && strpos($operations[0],'`notify_claimed_at`')!==false,'Interrupted migration resumes the remaining column');
$fixture_columns[]='notify_claimed_at'; $operations=array(); update_queue_topic_notification_columns($operations,null,'fixture','custom_topics_watch');
migration_check(!$operations,'Repeated migration is a no-op');
$fixture_table_exists=false; $fixture_columns=array(); $operations=array(); update_queue_topic_notification_columns($operations,null,'fixture','custom_topics_watch');
migration_check(!$operations,'No attempt to alter an absent base table');
$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
migration_check(preg_match("/notify_claim char\(32\) NOT NULL default ''/i",$schema) && preg_match("/notify_claimed_at int\(10\) UNSIGNED NOT NULL default '0'/i",$schema),'Fresh install and existing-install migration agree');
echo "Topic notification additive migration and resumption checks passed.\n";
