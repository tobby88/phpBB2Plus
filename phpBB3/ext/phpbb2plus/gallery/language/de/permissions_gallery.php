<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array('ACL_A_PLUS_GALLERY' => 'Kann das Fotoalbum verwalten'));
$lang['ACL_U_PLUS_ALBUM_PERSONAL'] = 'Kann bei beschränkter Gruppenfreigabe ein persönliches Album anlegen';
$lang['ACL_A_PLUS_ALBUM_CATEGORIES'] = 'Kann die allgemeinen Fotokategorien und deren Zugriffsstufen verwalten';
$lang['ACL_A_PLUS_ALBUM_AUTH'] = 'Kann Gruppenrechte für allgemeine Fotokategorien verwalten';
$lang['ACL_A_PLUS_ALBUM_PERSONAL_AUTH'] = 'Kann Gruppenrechte für die Anlage und Nutzung persönlicher Fotoalben verwalten';
$lang['ACL_A_PLUS_ALBUM_CACHE'] = 'Kann automatisch erzeugte Album-Vorschaubilder leeren';
