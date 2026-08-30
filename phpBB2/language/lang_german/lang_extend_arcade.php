<?php
/***************************************************************************
 *                         lang_arcade.php [german]
 *                         -------------------------
 *   begin                : Tuesday 27th November, 2006
 *   copyright            : (c) 2003-2006 dEfEndEr - www.phpbb-arcade.com
 *   email                : support@phpbb-arcade.com
 *
 *   German translation by :  femu info ( at ) die-muellers.org
 *
 *   $Id: lang_arcade.php, v2.1.4 2006/11/27 23:59:59 dEfEndEr Exp $
 *
 *	Based on the Original Activity Mod by Napoleon.
 *
 ***************************************************************************
 *
 *   This language file is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************
 * 	CREDITS:
 *  Whoo - Games and part code
 *  Napoleon - Original Activity Mod v2.0.0
 *	Buddystuart
 *  Minesh - Add-On's
 *  Painkiller
 *  Mark
 *  ~Maverick~ - Add-On's
 *  Zorial - Add-On's
 *  qx17417 - beta testing
 *  Madman - Chief Tester :)
 *	Others - Everybody else that helps with the project.
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}
//
// General
//
$lang['Edit_Games'] = 'Spiele ändern';
$lang['None'] = 'Keine';
$lang['All'] = 'Alle';
$lang['all_games'] = 'Alle Spiele';
$lang['arcade_ruffle_loading'] = 'Flash-Spiel wird mit Ruffle geladen&hellip;';
$lang['arcade_ruffle_error'] = 'Das Flash-Spiel konnte nicht mit Ruffle gestartet werden.';
$lang['arcade_media_fallback'] = 'Mediendatei öffnen';
$lang['arcade_format_unsupported'] = 'Dieses veraltete Spielformat wird von aktuellen Browsern nicht mehr unterstützt: %s';
//
// 2.0.6
//
$lang['date_added'] = 'Hinzugefügt am';
$lang['alphabetically'] = 'Alphabetisch';
$lang['games_not_enough_posts'] = 'Sorry. Dein Level reicht nur zum Gastlevel.<br /><br />Du mußt im Forum schon ein bisschen aktiver werden, um einen besseren Level zu erreichen.<br /><br />[%s]';
$lang['Played_Times'] = 'Gespielt: %d mal.';
//
// 2.0.7
//
$lang['cat_info_stats'] = 'Info / Statistiken';
$lang['cat_total_games'] = 'Gesamt';
$lang['cat_total_played'] = 'Gespielt';
$lang['cat_last_played'] = 'Zuletzt gespielt';
$lang['games_test_noscore'] = 'Als Gast kannst Du keine Highscores senden.<br />';
$lang['games_highscore'] = '<br /><b>Bester Spieler ';
$lang['games_athighscore'] = '<br /><b>Bester Spieler insgesamt ';
$lang['highscore_you_have'] = ' Du hast:</b><br /><br />';
$lang['place'] = ' Platz';
$lang['places'] = ' Plätze.';
$lang['games_position_text'] = array('> 20. Platz', '. Platz', '. Platz', '. Platz', '. Platz');
$games_position_text = $lang['games_position_text'];
//
// 2.1.2
//
$lang['Arcade'] = 'Spielhalle';
//
// Arcade
//
$lang['not_enough_points'] = 'Du hast nicht genügend Punkte (%s), um dieses Spiel zu spielen. <br /><br /><a href="javascript:parent.window.close();" alt="[Schließe Fenster]"><img src="images/close.gif"></a>';
$lang['not_enough_reward'] = 'Du hast nicht genügend Gegenleistungen, um dieses Spiele zu spielen.. <br /><br /><a href="javascript:parent.window.close();">[Fenster schließen]</a>';
$lang['game_instructions'] = 'Anleitung';
$lang['game_no_instructions'] = 'Keine Anleitung vorhanden.';
$lang['game_free'] = 'Frei';
$lang['game_cost'] = 'Kosten';
$lang['game_dash'] = ':';
$lang['game_number'] = '#';
$lang['game_points'] = 'Punkte';
$lang['game_list'] = $board_config['sitename'] . ' Spiele / Aktivitäten';
$lang['game_score'] = 'Punktzahl';
$lang['game_info'] = 'Info';
$lang['game_bonuses'] = 'Bonus';
$lang['game_best_player'] = 'Bester Spieler';
$lang['game_highscores'] = 'Bestenliste';
$lang['game_current_highscores'] = 'Aktuelle Bestenliste';
$lang['game_all_time_highscores'] = 'Ewige Bestenliste';
$lang['game_highscore'] = 'Bestes Ergebnis';
$lang['game_at_highscores'] = 'Insgesamt bestes Ergebnis';
$lang['game_new_high_score'] = '<b>*** Glückwunsch - Du hast Dein bisher bestes Ergebnis erreicht ***</b><br /><br />';
$lang['game_new_at_high_score'] = '<b>*** Glückwunsch - Du hast das insgesamt beste Ergebnis erreicht ***</b><br /><br />';
$lang['game_score_saved'] = 'Punkte wurden gespeichert';
$lang['game_score_updated'] = 'Ergebnis wurde aktualisiert';
$lang['game_score_text'] = '<b>%s</b> Deine %s<br /><br />';
$lang['game_highscore_off'] = '<br><br>Punktzahlbewertung ist ausgeschaltet.';
$lang['game_no_score_saved'] = 'Du hast keine Punkte erreicht. Darum wurde nichts gespeichert<br />';
$lang['game_no_high_score'] = ' erreichten Punkte sind leider nicht besser, als Dein bisheriges Ergebnis.';
$lang['game_score_close'] = 'Schließen';
$lang['game_cheater'] = 'Ruiniert!';
$lang['game_statistics'] = 'Spiele Statistiken';
$lang['game_played'] = 'Gespielt';
$lang['game_stat_price'] = 'Preis zum spielen';
$lang['game_stat_highscore'] = 'Bonus für beste Punktzahl';
$lang['game_stat_at_highscore'] = 'Bonus für insgesamt beste Punktzahl';
$lang['game_score_reward'] = 'Punktzahl Belohnung';
$lang['game_all_time_score'] = 'Punkte insgesamt';
$lang['game_current_best'] = 'Aktuell bester Spieler';
$lang['game_highest_score'] = 'Höchste Punktzahl';
$lang['game_score_your'] = 'Dein';
//
// 2.0.4
//
$lang['game_welcome'] = 'Willkommen bei %s, ';
$lang['game_guest_welcome'] = 'Willkommen bei %s! Bitte ';
$lang['game_stats'] = 'Spiel Statistik und Information';
$lang['game_tournament'] = 'Wettbewerb';
$lang['Game_Select'] = 'Wähle ein Spiel';
$lang['Active_Tournaments'] = 'Es gibt <b>%s</b> aktive Wettbewerbe.';
$lang['at_score_no_guest'] = 'Sorry, Gäste können keine Punkte speichern.<br /><br />Registriere Dich und vielleicht kannst Du dann schon bald eine der beliebten Trophäen in der Hand halten ...<br />';
$lang['total_games'] = 'Wir haben insgesamt <b>%s</b> Spiele.';
$lang['total_games_played'] = 'Insgesamt wurden in diesem Bereich <b>%s</b> Spiele gespielt.';
$lang['games_are_offline'] = 'Sorry, die Spielhalle ist im Moment nicht verfügbar. Bitte versuche es später wieder.';
$lang['games_register'] = ' - um Zugriff zu noch mehr Spielen zu erhalten - Die Registrierung ist KOSTENLOS.<br />';
$lang['games_top_header'] = 'Top %d Spiele';
$lang['games_bum_header'] = 'Spezial Spiel';
$lang['games_catagories'] = 'Spiele Kategorien';
$lang['games_section'] = 'Kategorie';
$lang['games_total_points'] = 'Du hast <b>%s</b> %s.<br />';
$lang['games_top_players'] = 'Die besten Spieler sind:<br />';
$lang['game_your_score'] = 'Dein <i><b>%s</b></i> mit \'<b>%s</b>\' Punkten wurde übertragen.<br /><br />';
$lang['game_hidden'] = 'Versteckt';
//
// 2.0.6
//
$lang['remove_fav_data'] = 'Konnte die Daten aus den Favoriten nicht entfernen.';
$lang['insert_fav_data'] = 'Konnte die Daten nicht in die Favoriten einfügen.';
$lang['no_fav_topic'] = 'Es wurde nichts ausgewählt, um es zu den Favoriten zu schreiben.';
$lang['play_favorites'] = 'Spiele Deine Favoritenliste';
$lang['add_fav'] = 'Zu den Favoriten hinzufügen';
$lang['del_fav'] = 'Von der Favoritenliste gelöscht<br /><br />';
$lang['already_fav'] = 'Ist bereits in der Favoritenliste vorhanden<br /><br />';
//
// 2.0.7
//
$lang['games_important_info'] = "Wichtige Information von der Spielhalle";
$lang['games_pm_info'] = "Deine beste Punktzahl wurde angesehen, aber der-/diejenige weiß nicht, daß Du darüber informiert wurdest. \n\nEs könnte sein, daß Du Deine Platzierung an ihm/sie verlierst, wenn Du nicht aufpasst ...!\n\n\nGruß - Der Spielhallenchef\n\n(Du kannst die Benachrichtigung in Deinem Profil deaktivieren)";
$lang['games_new_header'] = 'Die %d neusten Spiele';
$lang['games_play_again'] = '<img src="images/play_again.gif" alt="[Nochmal spielen]" title="[Nochmal spielen]" border="0" />';
$lang['games_add_fav'] = 'Zu den Favoriten hinzufügen';
$lang['games_remove_fav'] = 'Aus den Favoriten entfernen';
$lang['games_best_player'] = '<b><u>Bester Spieler</b></u><br /><img src="images/crown.gif" alt="[Bester Spieler]" title="[Bester Spieler]"border="0" /> <b><i><a href="%s">%s</a></b></i> mit <a href="%s"><b>%d</b> Siegen.</a><br />';
$lang['games_best_at_player'] = '<b><u>Bester Spieler insgesamt</b></u><br /><img src="images/crown.gif" alt="[Bester Spieler insgesamt]" title="[Bester Spieler insgesamt]" border="0" /> <b><i><a href="%s">%s</a></b></i> mit <a href="%s"><b>%d</b> Siegen.</a><br />';
$lang['games_last_viewed'] = 'Zuletzt angesehen/gespielt wurde <b>%s</b>';
$lang['games_last_u_viewed'] = 'Zuletzt angesehen/gespielt hast Du <b>%s</b>';
$lang['games_time_taken'] = 'Zeit';
$lang['games_unrecorded'] = 'Nicht eingetragen';
$lang['games_seconds'] = '%d Sekunden';
$lang['Game'] = 'Spiel';
//
//  Updated for 2.0.8.2
//
$lang['games_pm_info_lost'] = "Du hast Dein bestes Ergebnis für '<b>[url=http://%sactivity.".$phpEx."?mode=game&amp;id=%d&win=self]%s[/url]</b>' an den Spieler verloren, von dem diese Nachricht stammt.\n\n\nGruß - Der Spielhallenchef\n\n(Du kannst die Benachrichtigung in Deinem Profil deaktivieren)";
$lang['games_pm_info_lost_at'] = "Du hast Dein insgesamt bestes Ergebnis für '<b>[url=http://%sactivity.".$phpEx."?mode=game&amp;id=%d&win=self]%s[/url]</b>' an den Spieler verloren, von dem diese Nachricht stammt.\n\n\nGruß - Der Spielhallenchef\n\n(Du kannst die Benachrichtigung in Deinem Profil deaktivieren)";
//
// 2.0.8
//
$lang['games_minutes'] = '%d Min. %d Sek.';
$lang['games_hours'] = '%d Std. %d Min. %d Sek.';
$lang['games_days'] = '<b>%d Tage</b> %dh %dm %ds';
$lang['games_reward_givem'] = 'Vergütung für %s erhaltenen Punkte';
$lang['newscore_return'] = '<br />[<a href="activity.'.$phpEx.'">Zur Spielhalle</a>]';
$lang['return_to_arcade'] = 'Klicke %shier%s, um zurück zur Spielhalle zu gelangen';
$lang['game_size'] = '<br /><i>Dateigröße: %ld KB.</i>';
$lang['ON'] = 'AN';
$lang['OFF'] = 'AUS';
$lang['No_Instructions'] = 'Keine Anleitung vorhanden';
$lang['games_image_default'] = '<span class="gensmall"><i>{Standard}</i></span>';
$lang['games_not_enough_posts'] = 'Du musst im Forum schon etwas aktiver sein, um hier Zugriff zu erhalten';
//
// 2.1.2
//
$lang['games_last_score_gained'] = '<br /><b>%s</b> hat zuletzt <b>%s</b> Punkte mit <b>%s</b> erreicht!';
$lang['games_new_game_added_info'] = 'Neues Spiel in der Spielhalle eingetroffen';
$lang['games_new_game_added'] = "Ein neues Spiel mit dem Namen '%s' wurde gerade in der Spielhalle eingebaut. Sei der erste, der sich daran versucht!\n\n\nGruß - Der Spielhallenchef\n\n(Du kannst die Benachrichtigung in Deinem Profil deaktivieren)";
//
//  2.1.3
//
$lang['allow_guests'] = 'Gäste';
$lang['arcade_searched'] = 'Suche %s nach %s';
$lang['Comments'] = 'Kommentare';
$lang['incorrect_category'] = 'Falsche Kategorie gewählt. Bitte benutze die vorgegebenen.';
$lang['register_to_play'] = '***** <a href="profile.php?mode=register">Registriere Dich</a>, um zu spielen *****';
$lang['games_updated_points'] = 'Du erhältst %s %s für dieses Ergebnis<br />';
$lang['category'] = 'Kategorie';
$lang['control'] = 'Steuerung';
$lang['times'] = 'mal.';
$lang['arcade_mouse'] = 'Maus';
$lang['arcade_keyboard'] = 'Tastatur';
$lang['games_time_held'] = 'Zeit gehalten';
$lang['games_day'] = '<b>%d Tag</b> %dh %dm %ds';
$lang['games_weeks'] = '<i>%d Wochen</i> <b>%d Tage</b>';
$lang['games_weeks_only'] = '<i>%d Wochen</i>';
$lang['Block_Arcade_pm'] = 'Deaktiviere Benachrichtigungen von der Spielhalle';
$lang['Information'] = 'Information';
$lang['Action'] = 'Aktion';

//
//  v2.1.8
//
$lang['cat_jump_to'] = 'Springe zur Kategorie';

//
//  Comment System (2.0.8)
//
$lang['arcade_comment_edit'] = 'Kommentar bearbeiten';
$lang['arcade_comment_delete'] = 'Kommentar löschen';
$lang['arcade_comment_sure'] = 'Bist Du sicher, daß Du den Kommentar löschen willst?';
$lang['no_comment_text'] = 'Sorry, Du hast nicht genügend Informationen eingegeben.';
$lang['to_much_comment_text'] = 'Sorry, Du kannst hier nur Kommentare mit max. %d Zeichen eintragen.';
$lang['comment_poster'] = 'Absender:';
$lang['games_comment_added_info'] = "Es wurde ein neuer Kommentar zu Deinem Kommentar für %s hinterlassen!\n\n\nGruß - Der Spielhallenchef\n\n(Du kannst die Benachrichtigung in Deinem Profil deaktivieren)";
$lang['games_comment_info'] = "Re: Benachrichtigung vom Spielhallenchef";
//
//  2.1.2
//
$lang['arcade_added'] = 'Hinzugefügt';
$lang['arcade_comments'] = 'Kommentare';

/******************************************************************************
//  Rating System
******************************************************************************/
$lang['rating'] = 'Bewertung';
$lang['times'] = ' mal.';
$lang['already_rated'] = 'Bereits bewertet';
//
//  2.1.2
//
$lang['arcade_not_rated'] = 'Nicht bewertet';
$lang['arcade_rated'] = 'Bewertet';
$lang['arcade_title'] = 'Titel';
$lang['arcade_played'] = 'Gespielt';
$lang['arcade_current_rating'] = 'Aktuelle Wertung';
$lang['arcade_rate'] = 'Bewerte';
$lang['arcade_rate_return_cat'] = 'Klicke, um zu den %sKategorien%s zurückzukehren.';
$lang['arcade_rate_return_forum'] = 'Klicke, um zum %sForum%s zurückzukehren';


/*****************************************************************************
// Moderators
*****************************************************************************/
$lang['amod_mod_config_updated'] = 'Die Konfiguration der Spiele Moderatoren wurde aktualisiert<br /><br />';
$lang['moderators_options'] = 'Optionen für Moderatoren';
$lang['amod_admin_offline'] = '<b>Die Spielhalle deaktivieren</b><br />Sollen die Moderatoren die Möglichkeit haben, die Spielhalle zu deaktivieren?';
$lang['amod_admin_scores'] = '<b>Ergnisoption für Moderatoren</b><br />Welche Kontrolle sollen über die Ergebnisverwaltung sollen die Moderatoren erhalten?';
$lang['amod_admin_games'] = '<b>Spieleoption für Moderatoren</b><br />Sollen Moderatoren Spiele installieren/deinstallieren können?';
$lang['amod_admin_ban'] = '<b>Bneutzer bannen für Moderatoren</b><br />Sollen Moderatoren die Möglichkeit haben, Benutzer zu bannen (von der Bewertung auszunehmen)?';
//
// 2.0.8
//
$lang['arcade_mod_menu'] = 'phpBB Arcade Mod - Moderatoren Menü';
//
// 2.1.0
//
$lang['games_rate'] = "[Bewerte]";
$lang['games_add_comments'] = "[Kommentar]";
$lang['arcade_score_sure'] = "<br />Lösche Ergebnis '<b>%d</b>' für Benutzer '<b><i>%s</i></b>'<br /><br />Bist Du sicher?";

/******************************************************************************
// Tournament
******************************************************************************/
$lang['tournaments'] = 'Wettbewerbe';
$lang['tournament'] = 'Wettbewerb';
$lang['Active_Tournaments_link'] = 'Es gibt <b>%s</b> <a href="%s" class="gensmall" alt="View Tournaments">aktive Wettbewerbe</a>';
$lang['amod_tour_config_updated'] = 'Wettbewerbe wurde aktuallisiert.<br /><br />';
$lang['Total'] = 'Gesamt';
$lang['Join'] = 'Beitreten';
$lang['Start'] = 'Beginne';
$lang['Info'] = 'Information';
$lang['Stats'] = 'Statistik';
$lang['Data'] = 'Daten';
$lang['tournament_games'] = 'Spiele: <b>%d</b>';
$lang['tournament_players'] = 'Spieler: <b>%d</b>';
$lang['Min'] = 'Min.';
$lang['Max'] = 'Max.';
$lang['Yes'] = 'Ja';
$lang['No'] = 'Nein';
$lang['End'] = 'Ende';
$lang['No-One'] = 'Niemand';
$lang['has'] = 'hat';
$lang['have'] = 'hat';
$lang['tour_added']  = 'Wettbewerb hinzugefügt';
$lang['tour_add_players'] = '<br /><br /><a href="arcade_tournament.'.$phpEx.'?mode=add_players">Spieler hinzufügen</a><br /><br />';
$lang['tour_invite_players'] = '<br /><br /><a href="arcade_tournament.'.$phpEx.'?mode=invite_players">Spieler einladen</a><br /><br />';
$lang['tour_add_games'] = '<a href="arcade_tournament.'.$phpEx.'?mode=add_games">Spiele hinzufügen</a>';
$lang['tour_play_stats'] = 'Du hast %d Runden übrig<br>%s hat bisher das beste Ergebnis beim Spielen von <br>%s %s';
$lang['tour_not_part'] = 'Du nimmst an diesem Wettbewerb nicht teil<br>%s hat bisher das beste Ergebnis beim Spielen von<br>%s';
$lang['tour_no_join'] = 'Opps, Du hast nichts ausgewählt, um beizutreten';
$lang['tour_return'] = '<br><br>Klicke <a href="arcade_tournament.'.$phpEx.'">HIER</a>, um zum Wettbewerb zurückzukehren';
$lang['tour_joined'] = '<br>Du bist dem Wettbewerb %s beigetreten';
$lang['tour_full'] = '<br>Der Wettbewerb %s ist schon voll.';
$lang['tour_member'] = '<br>Du bist schon Mitglied beim Wettbewerb %s';
$lang['tour_save'] = '<b>Wettbewerbsergebnisse</b><br><br>';
$lang['waiting'] = 'Warten';
$lang['inactive'] = 'Inaktiv';
$lang['active'] = 'Aktiv';
$lang['complete'] = 'Komplett';
$lang['finished'] = 'Beendet';
$lang['running'] = 'Aktiv';
$lang['champion'] = 'Sieger';
$lang['champion_of'] = '&laquo; Der Sieger war &raquo;';
$lang['Champions'] = 'Letzter %d Sieger';
$lang['view_champions'] = 'Zeige die Siegerliste';
$lang['arcade_tournament_end_sure'] = 'Bist Du sicher, daß Du diesen Wettbewerb ändern willst?';
$lang['arcade_tournament_end'] = 'Wettbewerb <b>%s</b> ist nun beendet.';
$lang['tour_msg_subject'] = 'phpBB Arcade - Wettbewerbsinformation';
$lang['tour_msg_message'] = "\n\n\nUm die Ergbisse anzusehen, klicke [url=http://%sarcade_tournament.".$phpEx."]HIER[/url]\n\n\nGruß - Der Spielhallenchef\n\n(Du kannst die Benachrichtigung in Deinem Profil deaktiviern)";
$lang['tour_msg_winner'] = " mit <b>%s</b> lautet der Sieger.";
$lang['tour_msg_draw'] = ' wurde gezogen und neu gestartet ';
$lang['arcade_admin_only'] = 'Dieses Feature ist nur für den Admin verfügbar.';
$lang['Invite_Players'] = 'Spieler einladen';
$lang['Invite'] = 'Einladen';
$lang['Add_Games'] = 'Spiele hinzufügen';

/******************************************************************************
// If anything is changed below this line, then don't be surprised if you don't
// get very good support from the MOD Author. The next few lines deal with
// error handling & GPL licenses.
// By changing them you could break the law as well as cause errors.
//=============================================================================
// DO NOT TAKE THIS LINK OUT! Scott Porters Gamelib requires that this link is
// included by anyone using his library. If you don't have any games using
// gamelib, then turn it off and this link will not get displayed!
******************************************************************************/
$lang['game_lib_link'] = '<br />Einige der <b>JAVA</b> Spiele wurden mit &copy; <A HREF="http://www.javascript-games.org/gamelib/" TARGET="New_Window">GameLib</A> v2.08 erstellt.<br />Bei <A HREF="http://www.javascript-games.org" TARGET="New_Window">JavaScript Games</A> gibt\'s mehr Informationen dazu.';
$lang['activitiy_mod_info'] = 'phpBB Activity / Arcade Mod %s &copy 2000 - 2006 - Napoleon / dEfEndEr';

/******************************************************************************
// Errors
******************************************************************************/
$lang['no_main_data'] = 'Konnte die Hauptdaten nicht laden.';
$lang['no_game_data'] = 'Konnte die Spieledaten nicht laden.';
$lang['no_cat_update'] = 'Konnte Ketegoriedaten nicht aktualisieren.';
$lang['no_cat_data'] = 'Konnte die Kategoriedaten nicht laden.';
$lang['no_cat_data_enter'] = 'Kein Kategoriename oder Beschreibung - Speichern fehlgeschlagen';
$lang['no_game_update'] = 'Konnte die Spieledaten nicht aktualisieren';
$lang['no_game_total'] = 'Fehler beim Lesen der Gesamtdaten';
$lang['no_game_user'] = 'Fehler beim Lesen der Benutzer Spieldaten';
$lang['no_game_delete'] = 'Konnte das Spiel nicht löschen';
$lang['no_game_repair'] = 'Konnte die Spieletabellen nicht reparieren';
$lang['no_game_save'] = 'Konnte die Spieledaten nicht speichern';
$lang['no_user_data'] = 'Konnte die Benutzerdaten nicht lesen';
$lang['no_user_update'] = 'Konnte die BEnutzerdaten nicht aktualisieren';
$lang['no_score_data'] = 'Konnte die Ergbnisdaten nicht laden';
$lang['no_score_reset'] = 'Konnte die Ergebnisdaten nicht zurücksetzen';
$lang['no_score_insert'] = 'Konnte keine Ergebnisse eintragen';
$lang['no_score_reset'] = 'Konnte Ergebnisse nicht zurücksetzen';
$lang['no_config_data'] = 'Konnte die Konfiguration der Spiele nicht aufrufen';
$lang['no_config_update'] = 'Aktualisierung ist fehlgeschlagen für ';
$lang['no_game_info_data'] = 'Fehler, Keine Spieledaten erhalten<br />';
$lang['no_game_import'] = 'Keine Datei zum importieren/exportieren<br /><br />';
$lang['no_game_import_found'] = 'Importdatei nicht gefunden<br /><br />';
$lang['no_read_game_data'] = 'Fehler beim Lesen der Datei';
$lang['no_write_game_data'] = 'Fehler beim Schreiben in die Datei';
//
// 2.0.4 - 2.0.6
//
$lang['no_game_data_inform'] = 'FEHLER beim Lesen der Spieledaten.<br /><br />Dieser Fehler wird durch Deine Proxy Einstellung hervorgerufen.<br /><br />Die Registrierung erlaubt Dir die Ergebnisse zu speichern.';
$lang['session_error'] = 'Fehler beim Erstellen einer neuen Session.';
$lang['game_invalid_game'] = 'FEHLER - Ungültige Spieleoptionen erhalten<br /><br />Dieses Spiel wurde nicht für diesen Arcade MOD erstellt<br />';
$lang['no_special_play_games'] = 'Keine verfügbar';
$lang['games_no_guests'] = 'Sorry, Du darfst dieses Spiel nicht spielen.';
$lang['game_not_compatable'] = 'Dieses Spiel ist nicht kompatibel für diesen Arcade MOD.<br />';
//
// 2.0.7
//
$lang['game_move_error'] = 'Fehler. ein Spiel wurd vom Slot Zero gelöscht.<br /><br />Es wurde als neues Spiel hinzugefügt. Verschieben nicht durchgeführt - Versuche es nochmal.';
$lang['game_at_bottom'] = 'Du bist bereits ganz unten';
$lang['game_at_top'] = 'Du bist bereits ganz oben';
$lang['newscore_close'] = '<br /><a href="javascript:self.close();"><img src="images/close.gif" alt="[Fenster schließen]" border="0" /></a>';
/*****************************************************************************
						Only change the word CLOSE in the line ABOVE
*****************************************************************************/
//
// 2.0.8
//
$lang['no_arcadehash_data'] = 'Fehler beim Lesen der Spieledaten von der URL';
$lang['amod_update_error'] = 'Fehler beim aktualisieren der Dantenbank. Konnte keine Verbindung herstellen';
$lang['arcade_install_issue'] = 'Du hast die Installationanweisung nicht korrekt durchgeführt';
$lang['admin_arcade_reward_error'] = 'Du kannst nur ein Bewertungssystem installieren<br><br>';
$land['no_rate_data'] = 'Konnte Bewertungsdaten nicht laden';
$lang['no_rate_update'] = 'Konnte Bewertungsdaten nicht aktualisieren';
$lang['no_comment_data'] = 'Konnte Kommentardaten nicht laden';
$lang['no_comment_edit_data'] = 'Konnte Kommentardaten nicht bearbeiten';
$lang['no_comment_update'] = 'Konnte Kommentardaten nicht aktualisieren';
$lang['no_comment_found'] = 'Dieser Kommentar existiert nicht';
$lang['does_not_exist'] = 'Existiert nicht.';
$lang['no_activity'] = 'Kein Spiel ausgewählt';
$lang['bad_submitted_value'] = 'Schlechten Wert erhalten';
$lang['error_game_info_data'] = '<br>Unerwarteter Fehler - Falsches Spiel??<br>';
$lang['games_group_rank_limit'] = 'Sorry, mit Deinem Level hast Du hier keinen Zugriff.';
//
//  2.1.0
//
$lang['arcade_file_not_found'] = '<font color="#FFFFFF">Kann folgende Datei nicht finden: %s</font>';
//
//  2.1.2
//
$lang['newscore_close_first'] = '<br /><a href="javascript:self.close();opener.location.reload(true);"><img src="images/close.gif" alt="[Fenster schließen]" border="0" /></a>';
/*****************************************************************************^^^^^
						Only change the word CLOSE in the line ABOVE
*****************************************************************************/
$lang['arcade_incorrect_install'] = 'Inkorrekte Installation! Prüfe die install.txt und aktualisiere Deine includes/constants.php';
$lang['arcade_incorrect_version'] = 'Inkorrekte Installation! Version stimmt nicht. %s solte %s sein. Gehe ins ACP und führe die Update Routine aus';
$lang['arcade_user_held'] = 'Sorry, Dein Zugriff zur Spielhalle ist derzeit auf der Warteliste.';
$lang['arcade_user_banned'] = 'Sorry, Dein Zugriff auf die Spielhalle wurde ausgesetzt.';
//
//  2.1.3
//
$lang['class_invalid_data'] = 'Fehler -> %s <- ungültige Daten.';
$lang['game_id_error'] = 'Die eingebene Spiele ID existiert nicht.';
$lang['no_tour_data'] = 'Konnte Wettbewerbsdaten nicht laden';
$lang['no_tour_update'] = 'Konnte Wettbewerbsdaten nicht aktualisieren';
$lang['no_tour_delete'] = 'Konnte Wettbewerbsdaten nicht löschen';
$lang['no_tour_player_data'] = 'Konnte die Spielerdaten für den Wettbewerb nicht laden';
$lang['no_tour_update_data'] = 'Konnte die Spielerdaten für den Wettbewerb nicht aktualisieren';
$lang['no_tour_delete_data'] = 'Konnte die Spielerdaten für den Wettbewerb nicht löschen';
$lang['no_cookie_data'] = 'Sorry - Gäste müssen Cookies für diese Seite aktivieren<br />';
$lang['no_tour_play_data'] = 'Konnte Wettbewerbsspieledaten nicht laden';
$lang['arcade_incorrect'] = 'Ungültiger Systemaufruf.<br><br>Du kannst dieses Programm nicht über Internet aufrufen.';
$lang['game_not_available'] = 'Sorry, dieses Spiel ist derzeit nicht freigegeben.';
$lang['score_no_guest'] = 'Sorry, Gäste können keine Ergebnisse übertragen.<br /><br />Registriere Dich und dann kannst Du vielleicht die Trophäe in den Händen halten.<br />';
//
//  2.1.4
//
$lang['session_data_error'] = 'Konnte Sessiondaten nicht laden';
$lang['error_no_session'] = 'Arcade Session Fehler<br/ >';
$lang['incorrect_game_info_data'] = 'Ungültige Spieledaten erhalten<br />';
$lang['no_session_data'] = 'FEHLER: Keine Arcade Session gefunden<br />';
$lang['game_name_error'] = 'Ungültige Daten erhalten';
$lang['arcade_admin_must_play'] = 'Dieses Spiel wurde noch nicht von einem Admin/Moderator gespielt.<br><br>Darum kann ich noch keine Ergebnisse speichern ..!';
//
//  2.1.6
//
$lang['Your'] = 'Dein';
$lang['1st'] = '1.';
$lang['2nd'] = '2.';
$lang['3rd'] = '3.';
$lang['arcade_comments'] = 'Arcade Kommentar für ';
$lang['cats_no_access'] = 'Sorry, Du hast nicht den Level, um diese Kategorie zu öffnen';
$lang['game_repair_critical'] = '<br><b>Deine Arcade Konfiguration hat ein ernstes Problem. Du musst das Game-id feld manuell hinzufügen. Verwende dazu folgenden SQL Befehl, um Deine Datenbank zu aktualisieren (ggf. das  Prefix auf Dein verwendetes Prefix ändern!)</b><br><br><br><i>ALTER TABLE `phpbb_ina_games` ADD `game_id` MEDIUMINT( 9 ) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;</i><br><br />';
$lang['game_link_error'] = 'Link Datenfehler - Bitte verwende die Beschreibung als Link<br><br />';
//
//  2.1.8
//
$lang['arcade_link_visted'] = 'Link wurde %s mal besucht';
$lang['activitiy_mod__newinfo'] = 'Der phpBB Activity/Arcade MOD %s ist verfügbar';
//
// Monthly Highscore Mod
//
$lang['Highscore'] = 'Beste Ergebnisse';
$lang['highscore_table_error'] = 'Konnte Daten aus der Bestenliste nicht lesen';
$lang['highscore_jan'] = 'Januar';
$lang['highscore_feb'] = 'Februar';
$lang['highscore_mar'] = 'März';
$lang['highscore_apr'] = 'April';
$lang['highscore_may'] = 'Mai';
$lang['highscore_jun'] = 'Juni';
$lang['highscore_jul'] = 'Juli';
$lang['highscore_aug'] = 'August';
$lang['highscore_sep'] = 'September';
$lang['highscore_oct'] = 'Oktober';
$lang['highscore_nov'] = 'November';
$lang['highscore_dec'] = 'Dezember';
$lang['highscore_table_header'] = 'Bestenliste für';
$lang['highscore_submit'] = 'Zeige';
$lang['highscore_no_score'] = 'Kein Ergebnis hinzugefügt';
$lang['highscore_for'] = 'Beste Ergebnisse für';
$lang['highscore_count_err'] = "Konnte Dein bestes Ergbnis nicht zählen";
$lang['highscore_other_score'] = "Beste Ergebnisse für den Monat";
$lang['highscore_new_mon_score'] = '<b>Neues bestes Ergebnis für diesen Monat hinzugefügt</b>';
$lang['highscore_no_new_mon_score'] = 'Du hast kein neues bestes Ergebnis für diesen Monat erreicht.';

//
// TFFT - The End...!
//

?>
