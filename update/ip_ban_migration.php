<?php
// Additive only. Preserve every existing rule's old meaning and never guess
// whether a historical ff byte meant 255 or '*'. CLI migration library only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once dirname(__FILE__) . '/innodb_migration.php';
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
require_once dirname(__DIR__) . '/phpBB2/includes/functions_ban.php';

function plus_ip_ban_plan($db, $table)
{
    $quoted = plus_storage_identifier($table);
    $meta = plus_storage_metadata($db, $table);
    if (!$meta) { throw new RuntimeException('Required ban table is missing'); }
    $columns = plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted);
    $mask = null;
    foreach ($columns as $column) { if ($column['Field'] === 'ban_ip_mask') { $mask = $column; } }
    if ($mask === null) {
        foreach (plus_storage_rows($db, 'SELECT ban_ip FROM ' . $quoted) as $rule) { if (!phpbb_ip_ban_valid($rule['ban_ip'],'')) { throw new RuntimeException('Invalid existing legacy IP ban; review it before applying any update'); } }
        return array('ALTER TABLE ' . $quoted . " ADD ban_ip_mask CHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''");
    }
    if (strtolower($mask['Type']) !== 'char(8)' || $mask['Null'] !== 'NO' || !in_array($mask['Default'], array('', "''"), true) || $mask['Extra'] !== '') {
        throw new RuntimeException('Incompatible existing ban_ip_mask column; review it before applying any update');
    }
    foreach (plus_storage_rows($db, 'SELECT ban_ip,ban_ip_mask FROM ' . $quoted) as $rule) {
        if (!phpbb_ip_ban_valid($rule['ban_ip'],$rule['ban_ip_mask'])) { throw new RuntimeException('Invalid existing IP ban; review it without reinterpreting legacy rules'); }
    }
    if ($mask['Collation'] !== 'utf8mb4_unicode_ci') {
        // Preserve custom metadata as well as the existing explicit rules.
        // mysqli's native quoting uses the current mode and single delimiter.
        $comment = " COMMENT '" . mysqli_real_escape_string($db,$mask['Comment']) . "'";
        return array('ALTER TABLE ' . $quoted . " MODIFY ban_ip_mask CHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''" . $comment);
    }
    return array();
}
