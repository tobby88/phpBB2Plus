<?php
/***************************************************************************
 *                         lang_admin_arcade.php [German]
 *                         ------------------------------
 *   begin                : Tuesday 27th November, 2006
 *   copyright            : (c) 2003-2006 dEfEndEr - www.phpbb-arcade.com
 *   email                : support@phpbb-arcade.com
 *
 *   German translation by :  femu info ( at ) die-muellers.org
 *
 *
 *   $Id: lang_arcade.php, v2.1.4 2006/11/27 23:59:59 dEfEndEr Exp $
 *
 ***************************************************************************
 *
 *   This language file is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}

// Admin
$lang['admin_main_header'] = 'Mit Hilfe dieses Control Panels kannst Du Deine Online Spiele verwalten<br />Solltest Du irgendwelche Probleme mit unserem MOD haben, dann kontaktiere uns bitte unter <a href="http://www.phpbb-arcade.com"  target=_blank class="copyright">www.phpbb-arcade.com</a>. Dort können wir versuchen die Probleme zu lösen.';
$lang['admin_config_menu'] = 'Online Arcade Konfigurations Menü';
$lang['admin_game_menu'] = 'Online Arcade/Activity Menü';
$lang['admin_game_editor'] = 'Online Arcade/Activity Editor Menü';
$lang['admin_game_import'] = 'Online Arcade/Activity Import Menü';
$lang['admin_editor_info'] = 'Mit diesem Control Panel kannst neue Spiele hinzufügen. Jedes Spiel, das von <a href="http://www.phpbb-arcade.com" target="new_window">dEfEndEr</a>, Buddystuart, Whoo, Mullac oder Alegis veröffentlicht wurde, läßt sich ganz einfach in Dein Forum integrieren und die Punktezahlen speichern. Daneben können tausende frei downloadbare Spiele, Filme oder Bilder installiert werden, die Dein Forum drastisch aufwerden. Wenn Du ein Spiel konvertieren möchtest, das mit diesem Control zusammen funktioniert, dann frage uns in unserem <a href="http://www.phpbb-arcade.com/" target="new_window">Forum</a>. Solltest Du auf Probleme mit diesem Control Panel stossen, dann informiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank>www.phpbb-arcade.com</a>.';
$lang['admin_import_info'] = 'Mit diesem Control Panel kannst Du Daten direkt in die Datenbank importieren. Solltest Du auf Probleme mit diesem Control Panel stossen, dann informiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank>www.phpbb-arcade.com</a>.';
$lang['admin_game_deleted'] = 'Spiel gelöscht<br /><br />';
$lang['admin_game_not_deleted'] = 'Spiel NICHT gelöscht<br /><br />';
$lang['admin_game_repaired'] = 'Datenbank wurde repaiert<br /><br />';
$lang['admin_game_saved'] = 'Spiel wurde gespeichert<br /><br />';
$lang['admin_score_reset'] = 'Alle Punkte wurden zurückgesetzt<br /><br />';
$lang['admin_return_arcade'] = 'Klicke %shier%s, um zum Arcade Menü zurückzukehren';
$lang['admin_return_import'] = 'Klicke %shier%s, um zum Import Menü zurückzukehren';
$lang['admin_config_updated'] = 'Die Konfiguration wurde aktuallisiert<br /><br />';
$lang['admin_toggles'] = 'Support Schalter';
$lang['admin_rewards'] = 'Schalter für das Punktesystem';
$lang['admin_arcade_config'] = 'Arcade Konfiguration';
$lang['admin_use_adar_shop'] = '<b>Verwende den <a href="http://www.phpbb.com" target="newindow">Adar Item Shop</a></b><br />';
$lang['admin_use_adar_info'] = 'Wenn Du für erreichte Punkte Bonus vergeben willst und Du den Adar ItemShop verwendest, dann stelle diese Option auf <b>Ja</b>.<br /><br /><b>HINWEIS : </b>Das funktioniert nicht mit anderen Shops!';
$lang['admin_use_gamelib'] = '<b>Verwende die Gamelib Javascript Library</a></b><br />';
$lang['admin_use_gl_info'] = 'Wenn Du <i>JAVA</i> Spiele/Anwendungen benutzen willst, die Scott Porter\'s gamelib verwenden, dann stelle diese Option auf <b>Ja</>.';
$lang['admin_use_points'] = '<b>Verwende das <a href="http://www.phpbb-arcade.com" target="newindow">Points System</a></b><br />';
$lang['admin_use_pts_info'] = 'Wenn Du das Points System verwenden willst, dann stelle diese Option auf <b>Ja</b>, damit das Belohnungssystem in Deinem Forum aktiviert wird.';
$lang['admin_use_cash'] = '<b>Verwende das <a href="http://www.phpbb.com/community/" target="newindow">Cash System</a></b><br />';
$lang['admin_use_cash_info'] = 'Wenn Du das Cash System verwenden willst, dann stelle diese Option auf <b>Ja</b>, damit das Belohnungssystem in Deinem Forum aktiviert wird.';
$lang['admin_use_allowance'] = '<b>Verwende das <a href="http://www.phpbb-arcade.com" target="newindow">Allowance System</a></b><br />';
$lang['admin_use_allowance_info'] = 'Wenn Du das Allowance System verwenden willst, dann stelle diese Option auf <b>Ja</b>, damit das Belohnungssystem in Deinem Forum aktiviert wird.';
$lang['admin_games_path'] = '<b>Standard Spielepfad</b><br />';
$lang['admin_games_path_info'] = 'Das ist ein Verzeichnis in Deinem Forum, in das alle Deine Spiele gespeichert werden.';
$lang['admin_gl_game_path'] = '<b>Pfad für die Gamelib Spiele</b><br />';
$lang['admin_gl_path_info'] = 'Das ist der Pfad zu dem Verzeichnis in Deinem Forum Root Verzeichnis, der alle Spiele enthält, die die Gamelib verwenden.';
$lang['admin_gl_lib_path'] = '<b>Pfad zur Gamelib Javascript Library</b><br />';
$lang['admin_gl_lib_info'] = 'Das ist der Pfad zu Deinem Verzeichnis unterhalb des <b>Gamelib Games Verzeichnises</b>, daß alle gamelib*.js Dateien enthält.<br /><br /><b>HINWIES</b> : Sollte sich ein Spiel aufhängen oder Du keinerlei Sound hören kannst, dann überprüfe, ob dieses Verzeichnis irgendwelche Dateien enthält.';
$lang['admin_games_per_page'] = '<b>Spiele pro Seite</b><br />';
$lang['admin_games_per_info'] = 'Das gibt an, wieviele Spiele angezeigt werden, bevor eine neue Seite beginnt. (0 = Alle)';
$lang['admin_page'] = 'Seiten';
$lang['admin_game_id'] = 'Spiele ID#';
$lang['admin_path'] = 'Verzeichnis';
$lang['admin_adar_config'] = 'Adarian Shop Optionen';
$lang['admin_adar_shop'] = '<b>Adar Shop</b><br />';
$lang['admin_no_adar_info'] = 'Die Adarian Shop Optionen sind nicht nicht installiert. Installiere erst den Adar Shop, bevor Einstellungen an diesen Optionen vornimmst.';
$lang['admin_games'] = 'Spiele';
$lang['admin_charge'] = 'Abzüge';
$lang['admin_button'] = 'Schalter';
$lang['admin_description'] = 'Beschreibung';
$lang['admin_reward'] = 'Belohnung';
$lang['admin_bonus'] = 'Bonus';
$lang['admin_at_bonus'] = 'Bonus für AT';
$lang['admin_flash'] = 'Flash';
$lang['admin_score'] = 'Ergebnis';
$lang['admin_gamelib'] = 'Gamelib';
$lang['admin_action'] = 'Aktion';
$lang['admin_move'] = 'Verschieben';
$lang['admin_repair'] = 'Repariere den Spiele Index';
$lang['admin_reset'] = 'Setze High Scores zurück';
$lang['admin_at_reset'] = 'Setze ALL TIME Ergebnisse zurück';
$lang['admin_up'] = 'Hoch';
$lang['admin_down'] = 'Runter';
$lang['admin_down_full'] = 'Ganz unten';
$lang['admin_delete'] = 'X';
$lang['admin_delete_full'] = 'Lösche';
$lang['admin_limit'] = 'Limit';
$lang['admin_at_limit'] = 'AT Limit';
$lang['admin_width'] = 'Breite';
$lang['admin_height'] = 'Höhe';
$lang['admin_cash'] = 'Cash';
$lang['admin_name'] = '<b>Dateiname / phpBB Amod Spielename</b><br />';
$lang['admin_name_info'] = 'Das ist der Dateinamen Deines Spiels (Groß-/Kleinschreibung bechten!)<br /><br /><b>HINWEIS</b> : Schreibe den Namen exakt so, wie Du ihn vorfindest...!<br />Bei Spielen für den phpBB Amod/Activity Mod/Arcade Mod ist dieser HARD CODED. Deshalb muß der Name ohne die Erweiterung (.swf) eingegeben werden (Schalte dazu den FLASH Supoort unten auf <b>Ja</b>. Alles andere verursacht Fehler.';
$lang['admin_game_path'] = '<b>Pfad zur o.g. Datei</b><br />';
$lang['admin_game_path_info'] = 'Das ist das Verzeichnis innerhalb Deines Forums, in dem das Spiel gespeichert ist.';
$lang['admin_game_desc'] = '<b>Beschreibung</b><br />';
$lang['admin_game_desc_info'] = 'Das ist die Beschreibung, die Deinen Benutzern für dieses Spiel angezeigt wird.';
$lang['admin_game_charge'] = '<b>Kosten für das Spiel</b><br />';
$lang['admin_game_charge_info'] = 'Das gibt an, welchen Preis Deine Benutzer bezahlen müssen, wenn sie dieses Spiel spielen wollen.';
$lang['admin_game_per'] = '<b>Belohnung pro erzieltem Punkt</b><br />';
$lang['admin_game_per_info'] = 'Das gibt an, wieviele Punkte nötig sind, um 1 Belohnungspunkt zu erhalten.<br /><br /><b>Beispiel</b> : Wenn Du hier 100 eingibst und er 100 Punkte in diesem Spiel erreicht, dann erhält er 1 Belohnungspunkt.';
$lang['admin_game_bonus'] = '<b>Bonus für den Highscore</b><br />';
$lang['admin_game_bonus_info'] = 'Hier legst Du die Höhe der Belohnungspunkte an, die ein Spieler erhält, wenn er einen Highscore erreicht.';
$lang['admin_game_gamelib'] = '<b>Die Gamelib für dieses Spiel verwenden</b><br />';
//
//  Words changed in 2.0.8
//
$lang['admin_game_gamelib_info'] = 'Setzte diese Option auf JA, wenn das Java Spiel die <b>GameLib</b> verendet.';
$lang['admin_game_flash'] = '<b>Macromedia Flash Game Typ</b><br />';
$lang['admin_game_flash_info'] = 'Für Activity Mod/pnFlashGames/Arcade Mod & IBPro Spiele benötigt der obige Dateinamen keine Endung (.swf) und sollte daher auf JA stehen..';
//
$lang['admin_game_show_score'] = '<b>Zeige die Ergebnisse</b><br />';
$lang['admin_game_show_info'] = 'Setze diese Option auf <b>Ja</b>, wenn Du für dieses Spiel die Ausgabe von Ergebnissen haben willst.';
$lang['admin_game_reverse'] = '<b>Umgekehrte Anzeige der Highscore Liste</b><br />';
$lang['admin_game_reverse_info'] = 'Setze diese Option auf <b>Ja</b>, wenn Du die Highscore Liste in umgekehrter Reihenfolge (niedrigstes Ergebis zuerst) darstellen lassen willst.';
$lang['admin_game_highscore'] = '<b>Highscore Limit</b><br />';
$lang['admin_game_highscore_info'] = 'Das gibt an, wieviele Ergebnisse für dieses Spiel angezeigt werden sollen. Wenn Du alle anzeigen willst, dann lasse dieses Feld leer.';
$lang['admin_game_size'] = '<b>Fenstergröße</b><br />';
$lang['admin_game_size_info'] = 'Das gibt an, wie groß das Fenster in Pixeln sein soll, wenn das Spiel aufgerufen wird.';
$lang['instructions_info'] = 'Gib hier die "Spieleanleitung" ein. HTML Tags sind hier erlaubt.<br />';
$lang['admin_game_reset_hs'] = '<b>Highscores zurücksetzen</b><br />';
$lang['admin_game_reset_hs_info'] = 'Wenn Du diese Option auf <b>Ja</b> stellst, werden alle Highscore Ergebnisse zurückgesetzt.';
$lang['admin_game_reset_at_hs'] = '<b>ALL-TIME Ergebnisse zurücksetzen</b><br />';
$lang['admin_game_reset_at_hs_info'] = 'Wenn Du diese Option auf <b>Ja</b> stellst, werden alle ALL TIME Highscores zurückgesetzt.';
$lang['admin_use_rewards'] = '<b>Verwenden den Rewards MOD</b><br />';
$lang['admin_use_rewards_info'] = 'Wenn Du einen Rewards MOD installiert hast [Points/Cash/Allowance], dann setze diese Option auf <b>Ja</b>, um Deinen Rewards MOD zu konfigurieren.';
$lang['admin_cheat'] = '<b>Verwende den Mogel Modus</b><br />';
$lang['admin_cheat_info'] = 'Wenn diese Option auf <b>Ja</b> steht, werden Proxy <b>Gäste</b> & normale Gäste während der Session ausgeloggt und können KEIN Ergebnis speichern.';
$lang['admin_warn_cheater'] = '<b>Zeige den möglichen Moglern eine Warnung</b><br />';
$lang['admin_warn_cheater_info'] = 'Wenn diese Option aktiviert ist, wird den möglichen Moglern ein Hinweis angezeigt.';
$lang['admin_cheater_warning'] = '<br />Du wurdest dem <b>Admin</b> als möglicher Mogler angezeigt.<br /><br /> Solltest Du der Ansicht sein, daß Du nicht in einem Online Spiel gemogelt hast, dann kontaktiere bitte den Admin.<br />';
$lang['admin_warn_admin'] = '<b>Mogler melden</b><br />';
$lang['admin_warn_admin_info'] = 'Wenn diese Option aktiviert ist, erhält der Admin ein E-Mail mit einem Hinweis auf den möglichen Mogler.';
$lang['admin_cash_default_info'] = 'Der Arcade MOD verwendet nur 1 Belohnungsfeld. Bitte gib ein Standard Benutzerfeld für Deine Benutzer ein. [<b>Nur für den Cash MOD</b>]';
$lang['admin_games_offline'] = '<b>Den Arcade MOD deaktivieren</b><br />';
$lang['admin_games_offline_info'] = 'Damit kannst Du den Arcade MOD deaktiviern, um z.B. Wartungsarbeiten durchzuführen. Den Benutzern wird ein entsprechende NAchricht angezeigt.';
$lang['admin_default_game_id'] = '<b>Standard Spiele ID Nummer</b><br />';
$lang['admin_default_game_id_info'] = 'Verwende die Daten aus einem bestimmten Spiel, wenn Du ein neues Spiel installieren willst. Gib "0" ein, wenn alle Felder leer sein sollen.';
$lang['admin_cat'] = '<b>Spielekategorie</b><br />';
$lang['admin_cat_info'] = 'Die Kategorie, in der das Spiel gelistet wird.';
$lang['admin_default_img'] = '<b>Standard Bild</b><br />';
$lang['admin_default_img_info'] = 'Ein Bild, das angezeigt wird, wenn Du kein eigenes Bild für Dein Spiel zur Verfügung hast.';
//
// 2.0.4
//
$lang['admin_moderators_header'] = 'Moderatoren Menü';
$lang['admin_moderators_info'] = 'Mit diesem Control Panel kannst Du die Optionen für die Moderatoren im Kategoriemenü festlegen. <br /> Solltest Du mit diesem Menü auf Probleme stossen, dann kontaktieren uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>, damit wir das Problem lösen können.';
$lang['admin_default_txt'] = '<b>Standard Text, der Gästen angezeigt wird</b><br />';
$lang['admin_default_txt_info'] = 'Der Text den Gäste angezeigt bekommen und der sie zum Registrieren animieren soll.';
$lang['admin_tournament_txt'] = '<b>Verwende den Wettbewerbsmodus</b><br />';
$lang['admin_tournament_txt_info'] = 'Aktivieren den Wettbewerbsmodus.';
$lang['admin_played'] = 'Treffer';
$lang['admin_available'] = 'Deaktiviert';
$lang['admin_guest'] = 'Gäste';
$lang['admin_image_path'] = '<b>Pfad zum Spielebild</b><br />';
$lang['admin_image_path_info'] = 'Das Verzeichnis kann überall sein.<br />(wenn Du das Feld leer läßt, dann wird die dazugehörige Datei verwendet(dateiname.gif}. Bei der Endung .jpg und gleichem Dateinamen gib nur die Endung {.jpg} an.)';
//
// Changed in 2.0.8 from admin_game_level to admin_game_guest and Updated
//
$lang['admin_game_guest'] = '<b>Gästezugriff</b><br />';
$lang['admin_game_guest_info'] = 'Erlaube Gästen zu spielen (damit werden die nächsten drei Option überschrieben)';
$lang['admin_game_offline'] = '<b>Spiel ist verfügbar</b><br />';
$lang['admin_game_offline_info'] = 'Soll das Spiel für die Benutzer verfügbar sein? - Wenn Du diese Option auf <b>Nein</b> setzt, kannst Du das Spiel z.B. reparieren.';
$lang['admin_game_import_ok'] = '"%s" Spiele wurden importiert<br /><br />"%s" Spieledaten wurden übersprungen.<br /><br />';
$lang['admin_game_moderator_info'] = 'Der Name des Benutzers, der diesen Bereich moderieren soll.';
//
// 2.0.6
//
$lang['admin_game_exists'] = 'Der Name existiert bereits - Daten wurden nicht gespeichert<br /><br />';
$lang['admin_cat_menu'] = 'Kategorien Menü';
$lang['admin_cat_header'] = 'Mit diesem Menu kannst Du Deine Kategorien und die Spiele verwalten. Solltest Du hier auf Probleme stossen, dann kontaktiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>, damit wir diese lösen können.';
$lang['admin_cat_saved'] = 'Kategorie wurde gespeichert.<br /><br />';
$lang['admin_cat_deleted'] = 'Kategorie wurde gelöscht.<br /><br />';
$lang['admin_cat_not_deleted'] = 'Kategorie wurde NICHT gelöscht.<br /><br />';
$lang['admin_cat_icon'] = '<b>Kategorien Bild</b><br />';
$lang['admin_cat_icon_info'] = 'Pfad zum Bild ür diese Kategorie. Wenn Du das Feld leer läßt, wird nichts angezeigt.';
$lang['admin_cat_name'] = '<b>Name der Kategorie</b><br />';
$lang['admin_cat_name_info'] = 'Der Name dieser Kategorie. Er wird nach dem Bild als Kategorie angezeigt.';
$lang['admin_game_special'] = '<b>Extra Spiel</b><br />';
$lang['admin_game_special_info'] = 'Anzahl der Spiele, die benötigt werden, um das Extra Spiel zu aktivieren. 0=DEAKTIVIERT.';
$lang['admin_games_per_admin_info'] = 'Gibt an, wieviele Spiele pro Seite angezigt werden sollen, bevor eine neue Seite im ACP beginnt. (0 = Alle)';
$lang['admin_games_image_txt'] = '<b>Größe für das Spielebild</b><br />';
$lang['admin_games_image_txt_info'] = 'Setzte hier die Größe des Spielebildes fest.';
$lang['admin_auto_size_txt'] = '<b>Verwende automatische Spielegröße</b><br />';
$lang['admin_auto_size_txt_info'] = 'Das überschreibt die Werte für Flash- und Bilderdateien, damit sie in der richtigen Größe geladen werden. <br />Das betrifft NICHT die Einstellungen der Automatischen Konfiguration für die Flash- und Bilddateien, wenn ein neues Spiel hinzugefügt wird!. ';
$lang['admin_guest_high_txt'] = '<b>Erlaube Gästen einen Highscore zu speichern.</b><br />';
$lang['admin_guest_high_txt_info'] = 'Gäste können NIE eine AT Highscore setzen. Damit wird auch das Speichern von normalen Highscores ausgeschaltet.';
$lang['admin_at_highscore_txt'] = '<b>Verwende das AT HighScore System</b><br />';
$lang['admin_at_highscore_txt_info'] = 'Verwende das All Time HighScore System, um Ergebnisse zu speichern.';
$lang['admin_show_stats_txt'] = '<b>Zeige die Statistiken</b><br />';
$lang['admin_show_stats_txt_info'] = 'Schalte diese Option aus, wenn Du keine Statistiken anzeigen willst.';
$lang['admin_return_games'] = 'Klicke %shier%s, um zum Spiele Menü zurückzukehren.';
//
// 2.0.7
//
$lang['admin_messages_header'] = 'Menü für Persönliche Nachrichten';
$lang['admin_messages_info'] = 'Hier kannst Du einstellen, welche privaten Nachrichten die Benutzer vom Arcade MOD erhalten sollen. Wenn Du mit diesem Menü Probleme haben solltest, dann kontaktiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>, damit wir diese lösen können.';
$lang['admin_moderators_txt'] = '<b>Verwende das Moderatoren Menü</b><br />';
$lang['admin_moderators_txt_info'] = 'Aktiviere das MCP (Moderatoren Control Panel).';
$lang['admin_min_posts_txt'] = '<b>Anzahl an Beiträgen, die ein Benutzer haben muß, um überhaupt Zugriff zum Arcade MOD zu erhalten.</b><br />';
$lang['admin_min_posts_txt_info'] = 'Benutzer unterhalb dieses Wertes bekommen nur die Spiele zu sehen, die auch Gästen zur Verfügung stehen. Zusätzliche erhalten Sie einen Hinweis darauf, daß sie sich im Forum aktiver beiteiligen müssen, damit sie mehr Spiele sehen können.';
$lang['admin_use_pms_txt'] = '<b>Verwende das Private Nachrichten System</b>';
$lang['admin_use_pms_txt_info'] = '<br />Wenn das aktiviert ist, wird den Benutzern bei Bedarf vom Arcade MOD eine PM gesendet.';
$lang['admin_min_rank_txt'] = '<b>Benötigter Rang zum Zugriff</b>';
$lang['admin_min_rank_txt_info'] = '<br />Wenn diese Option gesetzt ist, ist der Zugriff nur Benutzern erlaubt, die diesen oder einen höheren Rang haben.';
$lang['admin_game_wrong_name'] = 'Leerzeichen im Spielenamen oder dem Verzeichnisnamen sind <b>NICHT</b> erlaubt!<br /><br />';
$lang['admin_game_score_type'] = '<b>Speicherart der Ergebnisse</b><br />';
$lang['admin_game_score_type_info'] = 'Welche Methode soll zum Speichern der Ergebnisse verwendet werden? (Automatisch durch das Spielen des Admins/Moderators)';
$lang['Auto'] = 'Automatisch';
$lang['Extras'] = 'Extras';
$lang['admin_get_method'] = 'Napoleon\'s Methode';
$lang['admin_post_method'] = 'Whoo\'s Methode';
$lang['admin_new_method'] = 'dEfEndEr\'s Methode';
$lang['admin_game_autosize'] = '<b>Deaktiviere die AutoSize Einstellungen</b><br />';
$lang['admin_game_autosize_info'] = 'Diese Option überschreibt die ACP Einstellungen, wenn die Autosize Option eingeschaltet ist.';
$lang['admin_cat_image_txt'] = '<b>Größe des Kategorienbildes</b><br />';
$lang['admin_cat_image_txt_info'] = 'Setze hier die Größe des Bildes für die Kategorien fest.';
$lang['admin_rank_required'] = '<b>Benötigter Rang</b><br />Setze diese Option, wenn Du den Zugriff nur einem bestimmten Rang erlauben willst.';
$lang['admin_level_required'] = '<b>Benötigter Level</b><br />Setze diese Option, wenn Du den Zugriff nur einem bestimmten Level erlauben willst.';
$lang['admin_import_path'] = '<br />Gib den <b>Pfad und Namen</b> der Datei an, die die Spieledaten enthalten.<br /><br />';
$lang['admin_import_dir'] = '<br />Gib das <b>Verzeichnis</b> an, das die Spiele enthält.<br /><br />';
$lang['admin_import_amod'] = 'Welchen Dateityp importieren?';
$lang['admin_import_online'] = 'Importiere alle Spiele als:';
$lang['admin_auto_size'] = 'AutoSize funktioniert nur bei FLASH Dateien und BILDERN';
$lang['admin_show_played_txt'] = '<b>Zeige gespielte Spiele</b><br />';
$lang['admin_show_played_txt_info'] = 'Deaktiviere diese Option, um die \'Gespielte Spiele\' im Spiele Menü zu deaktivieren';
$lang['admin_show_all_txt'] = '<b>Zeige alle Spiele</b><br />';
$lang['admin_show_all_txt_info'] = 'Deaktiviere diese Option, wenn die Option \'Zeige alle Spiele\' im Kategorien Menü nicht angezeigt werden soll';
$lang['admin_show_new_txt'] = '<b>Zeige neue Spiele</b><br />';
$lang['admin_show_new_txt_info'] = 'Deaktiviere diese Option, damit die \'Neue Spiele\' Liste nicht angezeigt wird und statt dessen \'Extra Spiele\' (am wenigsten gespielte Spiele) angezeigt werden soll.';
$lang['admin_games_zero_txt'] = '<b>Text der Kategorie Null</b><br />';
$lang['admin_games_zero_txt_info'] = 'Dieser Text wird in der \'Alle Spiele\' Option angezeigt (wenn die Option verwendet wird)';
$lang['admin_num_top_games_txt'] = '<b>Anzahl der Top Spiele</b><br />';
$lang['admin_num_top_games_txt_info'] = 'Wieviele Spiele sollen in der Top X Spiele Liste angezeigt werden?';
//
// 2.0.8
//
$lang['admin_return_cats'] = 'Klicke %shier%s, um zum Kategorien Menü zurückzukehren.';
$lang['admin_no_guests'] = '<b>Blockiere jeglichen Gästezugriff</b><br />';
$lang['admin_no_guests_info'] = 'Alle Spieler müssen als Mitglied eingeloggt sein, um Zugriff zu erhalten.';
$lang['admin_ibPro_method'] = 'ibPro Methode';
$lang['admin_pnflashgames_method'] = 'pnFlashGames Methode';
$lang['admin_mixed_method'] = 'Gemischte Methode';
$lang['admin_return_games_new'] = 'Klicke %shier%s, um Spiele in dieser Kategorie zu beabeiten.';
$lang['admin_game_level'] = '<b>Benötigter Level</b><br />';
$lang['admin_game_level_info'] = 'Erlaube nur Admins / Moderatoren zu spielen';
$lang['admin_game_rank'] = '<b>Benötigter Rang</b><br />';
$lang['admin_game_rank_info'] = 'Limitiere Zugriff auf Benutzer, die diesen oder einen höheren Rang haben.';
$lang['admin_game_group'] = '<b>Benötigte Gruppe</b><br />';
$lang['admin_game_group_info'] = 'Limitiere den Zugriff auf diese Benutzergruppe.';
$lang['admin_ban_users_txt'] = '<b>Das automatische BAN System verwenden</b><br />';
$lang['admin_ban_users_txt_info'] = 'Aktiviere diese Option, um Admins/Moderatoren zu erlauben, einzelne Benutzer vom Arcade MOD zu bannen.';
$lang['admin_use_rate_txt'] = '<b>Bewertungssystem</b><br />';
$lang['admin_use_rate_txt_info'] = 'Deaktiviere diese Option um das Bewertungssystem auszuschalten.';
$lang['admin_use_comment_txt'] = '<b>Kommentar System</b><br />';
$lang['admin_use_comment_txt_info'] = 'Deaktiviere diese Option, um das Schreiben/Lesen von Kommentaren auuzuschalten.';
$lang['admin_category'] = 'Kategorie';
$lang['admin_file_not_found'] = '<br />Datei: <b><i>%s</b></i> NICHT GEFUNDEN<br />';
$lang['admin_min_group_txt'] = '<b>Benötigte Gruppe für Zugriff</b>';
$lang['admin_min_group_txt_info'] = '<br />Wenn diese Option auf MOD gestellt ist, haben nur Mitglieder dieser Gruppe Zugriff.';
//
//  2.1.2
//
$lang['admin_show_fav_txt'] = '<b>Zeige Favoriten</b><br />';
$lang['admin_show_fav_txt_info'] = 'Deaktiviere diese Option, um die \'Favoriten\' im Spiele Menü auszuschalten';
$lang['admin_vbulletin_method'] = 'vBulletin Methode';
//
//  2.1.3
//
$lang['admin_default_sort'] = '<b>Standard Sortierung</b><br />';
$lang['admin_default_sort_info'] = 'Die Standard Sortierung, wenn die Benutzer die Liste zum ersten mal aufrufen.';
$lang['admin_default_sort_type'] = '<b>Standard Sortierungsreihenfolge</b><br />';
$lang['admin_default_sort_type_info'] = 'Die Standard Sortierfolge (absteigend / aufsteigend).';
$lang['admin_new_for_txt'] = '<b>Zeige ein blinkendes NEW</b><br />';
$lang['admin_new_for_txt_info'] = 'Gibt an, wie lange in Tagen das blinkende *NEW* (0=AUS), Spiele als neu anzeigt.';
$lang['admin_rate_txt'] = '<b>Bereich für das Bewertungssystem</b>';
$lang['admin_rate_txt_info'] = '<br />Min. = 2, Max. = 20';
$lang['admin_cache_menu'] = 'phpBB Arcade - Cache Einstellungen -';
$lang['admin_cache_header'] = 'Mit diesem Control Panel kannst Du die Einstellungen für das Cache System des Arcade MODs festlegen. Solltest Du Probleme mit diesem Panel haben, dann kontaktiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>, damit wir Dir helfen können, diese zu lösen.';
$lang['admin_use_cache'] = '<b>Das Arcade CACHE System verwenden</b><br>';
$lang['admin_use_cache_info'] = 'Diese Option aktiviert das Cache System. Stelle sicher, daß das das /cache/ Verzeichnis mit CHKMOD 0666 (rw-rw-rw) gesetzt ist!';
$lang['admin_arcade_cache'] = 'Cache Dauer(1 Tag = 1440 Minuten - Setze 0, um den Wert auszuschalten)';
$lang['admin_mins'] = 'Minuten';
$lang['admin_config_cache'] = '<b>Zeit zwischen den Updates der</b> <i>/cache/arcade_config.php</i><br>';
$lang['admin_config_cache_info'] = 'Es wird automatisch aktuallisiert, wenn Änderungen im ACP durchgeführt wurden.';
$lang['admin_cat_cache'] = '<b>Zeit zwischen den Updates der</b> <i>/cache/arcade_categories.php</i><br>';
$lang['admin_cat_cache_info'] = 'Es wird automatisch aktuallisiert, wenn Änderungen im ACP durchgeführt wurden.';
$lang['admin_games_cache'] = '<b>Zeit zwischen den Updates der</b> <i>/cache/arcade_games_x.php</i><br>';
$lang['admin_games_cache_info'] = 'Es wird automatisch aktuallisiert, wenn Änderungen im ACP durchgeführt wurden.';
$lang['admin_highscore_cache'] = '<b>Zeit zwischen den Updates der</b> <i>/cache/arcade_best_player.php</i><br>';
$lang['admin_highscore_cache_info'] = 'Halte diesen Wert klein, da Du sonst Beschwerden erhalten könntest.';
$lang['admin_at_highscore_cache'] = '<b>Zeit zwischen den Updates der</b> <i>/cache/arcade_best_at_player.php</i><br>';
$lang['admin_at_highscore_cache_info'] = 'Dieser Wert kann bis zu 10080 betragen, abhängig von Deinen All Time Champions.';
$lang['admin_show_mhm'] = '<b>Verwende den monatlichen Highscore MOD</b><br>';
$lang['admin_show_mhm_info'] = 'Deaktiviere diese Option, wenn dieses Feature nicht verwenden willst.';
$lang['admin_control'] = '<b>Spielekontrolle</b><br />';
$lang['admin_control_info'] = 'Dies zeigt an, womit das Spiel gespielt wird.';
$lang['admin_mouse'] = 'Maus';
$lang['admin_keyboard'] = 'Tastatur';
$lang['admin_mouse_keyboard'] = 'Maus & Tastatur';
$lang['admin_both'] = 'Beides';
$lang['admin_edit_games'] = 'Spiele bearbeiten';
$lang['Submit'] = 'Übertragen';
$lang['Add'] = 'Hinzufügen';
$lang['admin_stats'] = 'Statistiken';
$lang['admin_score_top_score'] = ' Ergebnis(se) - Top Ergebnis : ';
$lang['admin_at_score_top_score'] = ' AT Ergebnis(se) - Top Ergebnis : ';
$lang['admin_comment'] = ' Kommentar';
$lang['admin_comments'] = ' Kommentare';
$lang['admin_rated'] = '<br>Bewertet : ';
$lang['admin_date_added'] = 'Hinzugefügt am';
$lang['admin_alphabetically'] = 'Alphabetisch';
$lang['admin_game_played'] = 'Gespielt';
$lang['admin_allow_guests'] = 'Gast';
$lang['admin_score_header'] = 'Arcade Ergebnis Editor';
$lang['admin_score_info'] = 'Mit diesem Control Panel kannst Du die Ergebnsisse Deiner Benutzer festlegen. Wenn Du mit diesem Panel Probleme haben solltest, dann kontaktiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>, damit wir die Probleme lösen köönnen.<br />';
$lang['admin_score_editor'] = 'Ergebnis Editor';
$lang['admin_score_options'] = '<br><br /><form method="POST" action="%s">%s<input type="Submit" name="confirm" value="Ja">&nbsp;&nbsp;&nbsp;<input type="Submit" name="cancel" value="Nein"></form>';
$lang['admin_points'] = 'Punkte';
$lang['arcade_delete_at_sure'] = 'Bist Du sicher, daß Du Ergebnisdaten löschen willst';
$lang['arcade_delete_scores_sure'] = 'Bist Du sicher, dass Du die aktuellen Ergebnisdaten löschen willst?';
//
//  v2.1.4
//
$lang['admin_ibprov3_method'] = 'IBPro v3 Methode';
$lang['game_skipped'] = '%s wurde übersprungen, da bereits die Spiele #%d mit dem gleichen Namen existiert.<br />';

//
//  v2.1.6
//
$lang['admin_cat_type'] = '<b>Kategorien Typ</b><br />';
$lang['admin_cat_type_info'] = 'Hauptkategorie (wird auf der Kategorienseite angezeigt), Subkategorie (wird auf der Spieleseite unter der Hauptkategorie angezeigt) oder Link (verwende die Beschreibeibung auch als Link)';
$lang['admin_cat_parent'] = '<b>Übergeordnete Kategorie</b><br />';
$lang['admin_cat_parent_info'] = 'Falls es eine Unterkategorie ist, diese auch als übergeordnete Kategorie verwenden';
$lang['admin_cat_group'] = '<b>Benötigte Benutzergruppe für diese Kategorie</b><br />';
$lang['admin_cat_group_info'] = 'Zugriff auf Mitglieder einer bestimmten Benutzergruppe beschränken';
$lang['admin_cat_main'] = 'Hauptkategorie';
$lang['admin_cat_sub'] = 'Subkategorie';
$lang['admin_cat_link'] = 'Link';
$lang['admin_cat_no_parent'] = '<br>Du MUSST die Hauptkategorie setzen, wenn Du eine Subkategorie verwenden willst<br><br />';
$lang['admin_cat_wrong_parent'] = '<br>Dein Hauptkategorie kann keine Subkategorie sein<br><br />';
$lang['admin_import_cat'] = 'Kategorie, in die importiert werden soll.';
$lang['admin_move_to'] = 'Verschiebe nach:';
$lang['admin_move'] = 'Verschiebe';
$lang['admin_move_failed'] = 'Du musst ein gültige Position zum Verschieben nach wählen';
//
//  v2.1.7
//
$lang['admin_cats_resynced'] = 'Kategorien Datenbank wurde neu synchronisiert<br /><br />';
$lang['admin_resync'] = 'Kategorien neu synchronisieren';

//
//  v2.1.8
//
$lang['admin_use_log'] = '<b>Das Arcade LOG System verwenden</b><br />';
$lang['admin_use_log_info'] = 'Hiermit werden alle Fehler ins Logfile geschrieben. Nachrichten, die die newscore.php generiert, werden IMMER aufgezeichnet.';
$lang['no_arcade_log'] = 'Die Arcade Fehler Logdatei konnte nicht geöffnet werden.';
$lang['admin_arcade_log'] = 'Arcade Fehler Logdatei anzeigen/bearbeiten';
$lang['admin_arcade_log_info'] = 'Mit diesem Control Panel kannst Du die Arcade Fehler Logdatei ansehen und bearbeiten..<br />Falls Du Probleme mit unserem MOD haben solltest, dann kontaktiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>. Hier können wir versuchen, Dein Problem zu lösen.';
$lang['arcade_log_purge'] = 'Logdatei löschen';
$lang['record_number'] = "Eintrag Nr.'";
$lang['arcade_log_records'] = 'Es gibt aktuell %s Einträge in der Logdatei.';

/******************************************************************************
// Admin SET Feature
******************************************************************************/
$lang['admin_set'] = 'Damit stellst Du die Arcade Kosten, Highscore Bonus, All Time Highscore Bonus, etc für alle Deine Spiele ein.<br>Bevor Du hier Änderungen durchführst, solltest Du ein Backup Deiner Datenbank durchführen.';
$lang['admin_set_header'] = 'Erstellen von Arcade MOD Masseneinstellungen';
$lang['admin_set_info'] = 'Dieses Control Panel erlaubt Dir ALLE Spiele Optionen mit dem u.s. Wert zu befüllen..<br><br><center><b>WARNUNG: Wenn Du dieses Feature erst einmal benutzt, gibt\'s KEIN ZURÜCK ..!</b></center><br />Falls Du mit unserem MOD Probleme haben solltest, dann kontaktiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>, damit wir die Probleme lösen können.<br />';
$lang['admin_set_warning'] = '<b>WARNUNG: Wenn Du dieses Feature verwendest, gibt es KEIN ZURÜCK ..!</b>';
$lang['admin_set_arcade'] = 'Setze Arcade Standards';
$lang['admin_set_charge'] = 'Setze Spielekosten';
$lang['admin_set_highscore'] = 'Setze Highscore Bonus';
$lang['admin_set_at_highscore'] = 'Setze All Time Highscore Bonus';

/******************************************************************************
// PM Message System
******************************************************************************/
$lang['amod_mess_new'] = 'Sende eine PM an alle Benutzer, wenn ein neues Spiel hinzugefügt wurde.';
$lang['amod_mess_highscore'] = 'Sende eine PM, wenn der Highscore geschlagen wurde.';
$lang['amod_mess_at_highscore'] = 'Sende eine PM, wenn der All Time Highscore geschlagen wurde.';
$lang['amod_mess_comment'] = 'Sende eine PM, wenn ein Kommentar zu einem bestehenden hinzugefügt wurde.';

/******************************************************************************
// Tournament
******************************************************************************/
$lang['admin_tournament_header'] = 'Wettbewerbs Menu';
$lang['admin_tournament_info'] = 'Mit diesem Control Panel kannst Du die Einstellungen für Deine Wettbewerbe festlegen.<br />Solltest Du Probleme mit diesem Panel haben, dann kontaktiere uns unter <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a>, damit wir die Probleme lösen können.<br />';
$lang['tournaments'] = 'Wettbewerbe';
$lang['add_tournament'] = 'Hinzufügen/Bearbeite Wettbewerb';
$lang['tournament_settings'] = 'Wettbewerbs Einstellungen';
$lang['tournament_options'] = 'Wettbewerbs Optionen';
$lang['tournament_max_number'] = '<b>Maximale Anzahl an Wettbewerben.</b>';
$lang['tournament_max_games'] = '<b>Maximale Anzahl an Spielen pro Wettbewerb.</b>';
$lang['tournament_max_players'] = '<b>Maximale Anzahl an Speilern pro Wettbewerb.</b>';
$lang['tournament_user_start'] = '<b>Erlaube Benutzern Wettbewerbe zu veranstalten.</b>';
$lang['tournament_name'] = '<b>Name des Wettbewerbs</b>';
$lang['tournament_name_info'] = '<br />Der Name, der allen Benutzern im Kategorien Menü angezeigt wird.';
$lang['tournament_desc'] = '<b>Beschreibung des Wettbewerbs</b>';
$lang['tournament_desc_info'] = '<br />Eine kurze Beschreibung des Wettbewerbs - sei dabei so hilfreich, wie möglich.';
$lang['tournament_max_player'] = '<b>Maximale Spielerzahl</b>';
$lang['tournament_max_player_info'] = '<br />Max. Anzahl der Spieler für <b>diesen</b> Wettbewerb.';
$lang['tournament_turns'] = '<b>Maximale Anzahl von Durchgängen</b>';
$lang['tournament_turns_info'] = '<br />Die Anzahl, wie oft ein Spiel gespielt werden kann.';
$lang['tournament_block'] = '<b>Blockiere NORMALE Spieler ?</b>';
$lang['tournament_block_info'] = '<br />Verhindert, daß NICHT teilnehmende Wettbewerbsspieler, dieses Spiel verwenden können, solange der Wettbewerb stattfindet.';
$lang['tournament_active'] = '<b>Wettbewerbe aktivieren?</b>';
$lang['tournament_active_info'] = '<br />Erlaubt es abgeschlossene Wettbewerbe zu reaktivieren.';
$lang['tournament_start'] = '<b>Beginn des Wettbewerbs</b>';
$lang['tournament_start_info'] = '<br />Wie wird der Wettbewerb gestartet?';
$lang['tournament_end'] = '<b>Ende des Wettbewerbs</b>';
$lang['tournament_end_info'] = '<br />Wie wird der Wettbewerb beendet?';
$lang['admin_tournament_updated']  = 'Wettbewerb wurde aktuallisiert<br /><br />';
$lang['admin_tournament_added']  = 'Wettbewerb wurde hinzugefügt<br /><br />';
$lang['admin_tournament_deleted']  = 'Wettbewerb wurde gelöscht<br /><br />';
$lang['admin_add_games'] = 'Spiele hinzufügen';
$lang['admin_add_players'] = 'Spieler hinzufügen';
$lang['admin_tournament_add_games'] = 'Arcade Wettbewerb - Spiele hinzufügen';
$lang['admin_tournament_select_games'] = 'Wähle aus, welche Spiele zum %s Wettbewerb hinzugefügt werden sollen';
$lang['error_no_tour_info'] = 'Kein Name/Beschreibung eingegeben';

$lang['tour_options'] = array('Wartend', 'Inaktiv', 'Aktiv', 'Beendet');
$lang['tour_start_options'] = array('Manuell', 'Min. Anzahl Spieler erreicht', 'Max. Anzahl an Spielern erreicht', 'Automatisch');
$lang['tour_end_options'] = array('Manuell', 'Alle Spiele gespielt', 'Automatisch');

?>
