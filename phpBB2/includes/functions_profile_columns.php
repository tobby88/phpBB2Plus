<?php
// Shared with account creation. Matches the canonical users schema, including
// post-install ALTER additions; custom metadata never owns these columns.
function phpbb_profile_definition_core_columns()
{
    return array(
        'user_id','user_active','username','user_password',
        'user_session_time','user_session_page','user_session_topic','user_lastvisit',
        'user_regdate','user_reg_ip','user_reg_host','user_level',
        'user_posts','user_timezone','user_style','user_lang',
        'user_dateformat','user_new_privmsg','user_unread_privmsg','user_last_privmsg',
        'user_login_tries','user_last_login_try','user_emailtime','user_viewemail',
        'user_attachsig','user_setbm','user_allowhtml','user_allowbbcode',
        'user_allowsmile','user_allowavatar','user_allow_pm','user_allow_viewonline',
        'user_notify','user_notify_pm','user_popup_pm','user_rank',
        'user_avatar','user_avatar_type','user_email','user_icq',
        'user_website','user_from','user_from_flag','user_sig',
        'user_sig_bbcode_uid','user_aim','user_yim','user_msnm',
        'user_fb','user_ig','user_pt','user_twr',
        'user_skp','user_tg','user_li','user_tt',
        'user_dc','user_signal','user_threema','user_occ',
        'user_interests','user_actkey','user_newpasswd','ct_last_pw_reset',
        'ct_enable_ip_warn','ct_last_used_ip','ct_last_ip','ct_last_pw_change',
        'ct_global_msg_read','ct_miserable_user','user_sub_forum','user_split_cat',
        'user_last_topic_title','user_sub_level_links','user_display_viewonline','user_birthday',
        'user_next_birthday_greeting','user_gender','user_color_group','user_lastlogon',
        'user_totaltime','user_totallogon','user_totalpages','user_calendar_display_open',
        'user_calendar_header_cells','user_calendar_week_start','user_calendar_nb_row','user_calendar_birthday',
        'user_calendar_forum','user_warnings','user_passwd_change','user_badlogin',
        'user_blocktime','user_block_by','user_split_global_announce','user_split_announce',
        'user_split_sticky','user_split_news','user_split_topic_split','user_absence',
        'user_absence_mode','user_absence_text','user_announcement_date_display','user_announcement_display',
        'user_announcement_display_forum','user_announcement_split','user_announcement_forum','user_use_ajax_preview',
        'user_use_ajax_edit','games_block_pm','arcade_banned'
    );
}
