<?php
/**  
* <b>acp_module_settings.php</b><br>
* This File is the ACP Module to manage all CrackerTracker Settings
* 
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 26.07.2006 - 13:29:09
* @copyright (c) 2006 www.cback.de
* 
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*/

// Constant check
if ( !defined('IN_PHPBB') || !defined('CTRACKER_ACP') )
{
	die('Hacking attempt!');
}


/*
 * Submit?
 */
if( isset($HTTP_POST_VARS['submit']) )
{
	phpbb_admin_require_post_session();
	$setting_ranges = array(
		'ipblock_enabled' => array(0, 1), 'ipblock_logsize' => array(1, 400),
		'search_feature_enabled' => array(0, 1), 'search_time_user' => array(1, 90),
		'search_count_user' => array(1, 6), 'search_time_guest' => array(1, 90),
		'search_count_guest' => array(1, 6), 'loginfeature' => array(0, 1),
		'logsize_logins' => array(1, 400), 'logincount' => array(5, 20),
		'login_history' => array(0, 1), 'login_history_count' => array(1, 60),
		'login_ip_check' => array(0, 1), 'spammer_blockmode' => array(0, 2),
		'spammer_postcount' => array(1, 12), 'spammer_time' => array(1, 90),
		'logsize_spammer' => array(1, 400), 'reg_protection' => array(0, 1),
		'reg_blocktime' => array(1, 200),
		'pw_control' => array(0, 1), 'pw_validity' => array(6, 365),
		'pw_complex' => array(0, 1), 'pw_complex_mode' => array(1, 9),
		'pw_complex_min' => array(1, 20), 'pw_reset_feature' => array(0, 1),
		'pwreset_time' => array(1, 180), 'massmail_protection' => array(0, 1),
		'massmail_time' => array(1, 180), 'auto_recovery' => array(0, 1),
		'vconfirm_guest' => array(0, 1), 'autoban_mails' => array(0, 1),
		'detect_misconfiguration' => array(0, 1), 'spam_attack_boost' => array(0, 1),
		'spam_keyword_det' => array(0, 2), 'request_limit_enabled' => array(0, 1),
		'request_limit_login' => array(5, 100), 'request_limit_register' => array(1, 50),
		'request_limit_account' => array(1, 100), 'request_limit_write' => array(20, 500),
		'request_limit_upload' => array(1, 100)
	);
	foreach ($setting_ranges as $setting_name => $range)
	{
		$value = intval(phpbb_admin_post_string($setting_name, isset($ctracker_config->settings[$setting_name]) ? $ctracker_config->settings[$setting_name] : $range[0]));
		$value = min($range[1], max($range[0], $value));
		$ctracker_config->change_configuration($setting_name, (string) $value);
		$ctracker_config->settings[$setting_name] = (string) $value;
	}	
}


/*
 * Generate Objects we need and initialize used vars
 */
$configuration = array();
$adminclass    = new ct_adminfunctions();

/*
 * Set correct configuration values
 * We do this for each config value seperate - more code but safer handling
 */
$configuration['ipblock_enabled'] 		  = $adminclass->ct_generate_on_off($ctracker_config->settings['ipblock_enabled']);
$configuration['ipblock_logsize']		  = $adminclass->ct_generate_number_field(1, 400, $ctracker_config->settings['ipblock_logsize']);
$configuration['search_feature_enabled']  = $adminclass->ct_generate_on_off($ctracker_config->settings['search_feature_enabled']);
$configuration['search_time_user']		  = $adminclass->ct_generate_number_field(1, 90, $ctracker_config->settings['search_time_user']);
$configuration['search_count_user']	      = $adminclass->ct_generate_number_field(1, 6, $ctracker_config->settings['search_count_user']);
$configuration['search_time_guest']       = $adminclass->ct_generate_number_field(1, 90, $ctracker_config->settings['search_time_guest']);
$configuration['search_count_guest']	  = $adminclass->ct_generate_number_field(1, 6, $ctracker_config->settings['search_count_guest']);
$configuration['loginfeature'] 			  = $adminclass->ct_generate_on_off($ctracker_config->settings['loginfeature']);
$configuration['logsize_logins']		  = $adminclass->ct_generate_number_field(1, 400, $ctracker_config->settings['logsize_logins']);
$configuration['logincount']	  		  = $adminclass->ct_generate_number_field(5, 20, max(5, intval($ctracker_config->settings['logincount'])));
$configuration['login_history'] 		  = $adminclass->ct_generate_on_off($ctracker_config->settings['login_history']);
$configuration['login_history_count']	  = $adminclass->ct_generate_number_field(1, 60, $ctracker_config->settings['login_history_count']);
$configuration['login_ip_check'] 		  = $adminclass->ct_generate_on_off($ctracker_config->settings['login_ip_check']);
$configuration['spammer_blockmode']		  = $adminclass->ct_spammer_block($ctracker_config->settings['spammer_blockmode']);
$configuration['spammer_postcount']	  	  = $adminclass->ct_generate_number_field(1, 12, $ctracker_config->settings['spammer_postcount']);
$configuration['spammer_time']       	  = $adminclass->ct_generate_number_field(1, 90, $ctracker_config->settings['spammer_time']);
$configuration['logsize_spammer']		  = $adminclass->ct_generate_number_field(1, 400, $ctracker_config->settings['logsize_spammer']);
$configuration['reg_protection'] 		  = $adminclass->ct_generate_on_off($ctracker_config->settings['reg_protection']);
$configuration['reg_blocktime']       	  = $adminclass->ct_generate_number_field(1, 200, $ctracker_config->settings['reg_blocktime']);
$configuration['pw_control'] 			  = $adminclass->ct_generate_on_off($ctracker_config->settings['pw_control']);
$configuration['pw_validity']			  = $adminclass->ct_generate_number_field(6, 365, $ctracker_config->settings['pw_validity']);
$configuration['pw_complex'] 			  = $adminclass->ct_generate_on_off($ctracker_config->settings['pw_complex']);
$configuration['pw_complex_mode']		  = $adminclass->ct_complex_mode($ctracker_config->settings['pw_complex_mode']);
$configuration['pw_complex_min']	      = $adminclass->ct_generate_number_field(1, 20, $ctracker_config->settings['pw_complex_min']);
$configuration['pw_reset_feature'] 		  = $adminclass->ct_generate_on_off($ctracker_config->settings['pw_reset_feature']);
$configuration['pwreset_time']       	  = $adminclass->ct_generate_number_field(1, 180, $ctracker_config->settings['pwreset_time']);
$configuration['massmail_protection'] 	  = $adminclass->ct_generate_on_off($ctracker_config->settings['massmail_protection']);
$configuration['massmail_time']       	  = $adminclass->ct_generate_number_field(1, 180, $ctracker_config->settings['massmail_time']);
$configuration['auto_recovery'] 		  = $adminclass->ct_generate_on_off($ctracker_config->settings['auto_recovery']);
$configuration['vconfirm_guest'] 		  = $adminclass->ct_generate_on_off($ctracker_config->settings['vconfirm_guest']);
$configuration['autoban_mails']	 	 	  = $adminclass->ct_generate_on_off($ctracker_config->settings['autoban_mails']);
$configuration['detect_misconfiguration'] = $adminclass->ct_generate_on_off($ctracker_config->settings['detect_misconfiguration']);
$configuration['spam_attack_boost'] 	  = $adminclass->ct_generate_on_off($ctracker_config->settings['spam_attack_boost']);
$configuration['spam_keyword_det'] 		  = $adminclass->ct_keyword_b_block($ctracker_config->settings['spam_keyword_det']);
$configuration['request_limit_enabled']  = $adminclass->ct_generate_on_off($ctracker_config->settings['request_limit_enabled']);
$configuration['request_limit_login']    = $adminclass->ct_generate_number_field(5, 100, $ctracker_config->settings['request_limit_login']);
$configuration['request_limit_register'] = $adminclass->ct_generate_number_field(1, 50, $ctracker_config->settings['request_limit_register']);
$configuration['request_limit_account']  = $adminclass->ct_generate_number_field(1, 100, $ctracker_config->settings['request_limit_account']);
$configuration['request_limit_write']    = $adminclass->ct_generate_number_field(20, 500, $ctracker_config->settings['request_limit_write']);
$configuration['request_limit_upload']   = $adminclass->ct_generate_number_field(1, 100, $ctracker_config->settings['request_limit_upload']);

/*
 * Kick Objects we don't use anymore now
 */
unset($adminclass);

/*
 * Output the page
 */
$template->set_filenames(array(
	'ct_body' => 'ctracker/acp/acp_settings.tpl')
);

// Send some vars to the template
$template->assign_vars(array(
		'L_CATNAME_1'  => $lang['ctracker_set_catname1'],
		'L_CATNAME_2'  => $lang['ctracker_set_catname2'],
		'L_CATNAME_3'  => $lang['ctracker_set_catname3'],
		'L_CATNAME_4'  => $lang['ctracker_set_catname4'],
		'L_CATNAME_5'  => $lang['ctracker_set_catname5'],
		'L_CATNAME_6'  => $lang['ctracker_set_catname6'],
		'L_CATNAME_7'  => $lang['ctracker_set_catname7'],
		'L_CATNAME_8'  => $lang['ctracker_set_catname8'],
		'L_CATNAME_9'  => $lang['ctracker_set_catname9'],
		
		'L_CT_HEAD'	   => $lang['ctracker_settings_head'],
		'L_CT_EXPL'	   => $lang['ctracker_settings_expl'],
		'L_SUBMIT'	   => $lang['ctracker_button_submit'],
		'L_RESET'	   => $lang['ctracker_button_reset'],
		
		'L_MOD_1'      => $lang['ctracker_settings_m1'],
		'L_EXP_1'	   => $lang['ctracker_settings_e1'],
		'L_MOD_2'      => $lang['ctracker_settings_m2'],
		'L_EXP_2'	   => $lang['ctracker_settings_e2'],
		'L_MOD_3'      => $lang['ctracker_settings_m3'],
		'L_EXP_3'	   => $lang['ctracker_settings_e3'],
		'L_MOD_4'      => $lang['ctracker_settings_m4'],
		'L_EXP_4'	   => $lang['ctracker_settings_e4'],
		'L_MOD_5'      => $lang['ctracker_settings_m5'],
		'L_EXP_5'	   => $lang['ctracker_settings_e5'],
		'L_MOD_6'      => $lang['ctracker_settings_m6'],
		'L_EXP_6'	   => $lang['ctracker_settings_e6'],
		'L_MOD_7'      => $lang['ctracker_settings_m7'],
		'L_EXP_7'	   => $lang['ctracker_settings_e7'],
		'L_MOD_8'      => $lang['ctracker_settings_m8'],
		'L_EXP_8'	   => $lang['ctracker_settings_e8'],
		'L_MOD_9'      => $lang['ctracker_settings_m9'],
		'L_EXP_9'	   => $lang['ctracker_settings_e9'],
		'L_MOD_10'     => $lang['ctracker_settings_m10'],
		'L_EXP_10'	   => $lang['ctracker_settings_e10'],
		'L_MOD_11'     => $lang['ctracker_settings_m11'],
		'L_EXP_11'	   => $lang['ctracker_settings_e11'],
		'L_MOD_12'     => $lang['ctracker_settings_m12'],
		'L_EXP_12'	   => $lang['ctracker_settings_e12'],
		'L_MOD_13'     => $lang['ctracker_settings_m13'],
		'L_EXP_13'	   => $lang['ctracker_settings_e13'],
		'L_MOD_14'     => $lang['ctracker_settings_m14'],
		'L_EXP_14'	   => $lang['ctracker_settings_e14'],
		'L_MOD_15'     => $lang['ctracker_settings_m15'],
		'L_EXP_15'	   => $lang['ctracker_settings_e15'],
		'L_MOD_16'     => $lang['ctracker_settings_m16'],
		'L_EXP_16'	   => $lang['ctracker_settings_e16'],
		'L_MOD_17'     => $lang['ctracker_settings_m17'],
		'L_EXP_17'	   => $lang['ctracker_settings_e17'],
		'L_MOD_18'     => $lang['ctracker_settings_m18'],
		'L_EXP_18'	   => $lang['ctracker_settings_e18'],
		'L_MOD_19'     => $lang['ctracker_settings_m19'],
		'L_EXP_19'	   => $lang['ctracker_settings_e19'],
		'L_MOD_22'     => $lang['ctracker_settings_m22'],
		'L_EXP_22'	   => $lang['ctracker_settings_e22'],
		'L_MOD_23'     => $lang['ctracker_settings_m23'],
		'L_EXP_23'	   => $lang['ctracker_settings_e23'],
		'L_MOD_24'     => $lang['ctracker_settings_m24'],
		'L_EXP_24'	   => $lang['ctracker_settings_e24'],
		'L_MOD_25'     => $lang['ctracker_settings_m25'],
		'L_EXP_25'	   => $lang['ctracker_settings_e25'],
		'L_MOD_26'     => $lang['ctracker_settings_m26'],
		'L_EXP_26'	   => $lang['ctracker_settings_e26'],
		'L_MOD_27'     => $lang['ctracker_settings_m27'],
		'L_EXP_27'	   => $lang['ctracker_settings_e27'],
		'L_MOD_28'     => $lang['ctracker_settings_m28'],
		'L_EXP_28'	   => $lang['ctracker_settings_e28'],
		'L_MOD_29'     => $lang['ctracker_settings_m29'],
		'L_EXP_29'	   => $lang['ctracker_settings_e29'],
		'L_MOD_30'     => $lang['ctracker_settings_m30'],
		'L_EXP_30'	   => $lang['ctracker_settings_e30'],
		'L_MOD_31'     => $lang['ctracker_settings_m31'],
		'L_EXP_31'	   => $lang['ctracker_settings_e31'],
		'L_MOD_32'     => $lang['ctracker_settings_m32'],
		'L_EXP_32'	   => $lang['ctracker_settings_e32'],
		'L_MOD_33'     => $lang['ctracker_settings_m33'],
		'L_EXP_33'	   => $lang['ctracker_settings_e33'],
		'L_MOD_34'     => $lang['ctracker_settings_m34'],
		'L_EXP_34'	   => $lang['ctracker_settings_e34'],
		'L_MOD_35'     => $lang['ctracker_settings_m35'],
		'L_EXP_35'	   => $lang['ctracker_settings_e35'],
		'L_MOD_36'     => $lang['ctracker_settings_m36'],
		'L_EXP_36'	   => $lang['ctracker_settings_e36'],
		'L_MOD_37'     => $lang['ctracker_settings_m37'],
		'L_EXP_37'	   => $lang['ctracker_settings_e37'],
		'L_MOD_38'     => $lang['ctracker_settings_m38'],
		'L_EXP_38'	   => $lang['ctracker_settings_e38'],
		'L_MOD_39'     => $lang['ctracker_settings_m39'],
		'L_EXP_39'	   => $lang['ctracker_settings_e39'],
		'L_MOD_40'     => $lang['ctracker_settings_m40'],
		'L_EXP_40'	   => $lang['ctracker_settings_e40'],
		'L_MOD_41'     => $lang['ctracker_settings_m41'],
		'L_EXP_41'	   => $lang['ctracker_settings_e41'],
		'L_MOD_42'     => $lang['ctracker_settings_m42'],
		'L_EXP_42'	   => $lang['ctracker_settings_e42'],
		
		'CAT_ICON_1'   => $phpbb_root_path . $images['ctracker_icon_set_1'],
		'CAT_ICON_2'   => $phpbb_root_path . $images['ctracker_icon_set_2'],
		'CAT_ICON_3'   => $phpbb_root_path . $images['ctracker_icon_set_3'],
		'CAT_ICON_4'   => $phpbb_root_path . $images['ctracker_icon_set_4'],
		'CAT_ICON_5'   => $phpbb_root_path . $images['ctracker_icon_set_5'],
		'CAT_ICON_6'   => $phpbb_root_path . $images['ctracker_icon_set_6'],
		'CAT_ICON_7'   => $phpbb_root_path . $images['ctracker_icon_set_7'],
		'CAT_ICON_8'   => $phpbb_root_path . $images['ctracker_icon_set_8'],
		
		'S_OUTPUT_1'   => $configuration['ipblock_enabled'],
		'S_OUTPUT_2'   => $configuration['ipblock_logsize'],
		'S_OUTPUT_3'   => $configuration['search_feature_enabled'],
		'S_OUTPUT_4'   => $configuration['search_time_user'],
		'S_OUTPUT_5'   => $configuration['search_count_user'],
		'S_OUTPUT_6'   => $configuration['search_time_guest'],
		'S_OUTPUT_7'   => $configuration['search_count_guest'],
		'S_OUTPUT_8'   => $configuration['loginfeature'],
		'S_OUTPUT_9'   => $configuration['logsize_logins'],
		'S_OUTPUT_10'  => $configuration['logincount'],
		'S_OUTPUT_11'  => $configuration['login_history'],
		'S_OUTPUT_12'  => $configuration['login_history_count'],
		'S_OUTPUT_13'  => $configuration['login_ip_check'],
		'S_OUTPUT_14'  => $configuration['spammer_blockmode'],
		'S_OUTPUT_15'  => $configuration['spammer_time'],
		'S_OUTPUT_16'  => $configuration['spammer_postcount'],
		'S_OUTPUT_17'  => $configuration['logsize_spammer'],
		'S_OUTPUT_18'  => $configuration['reg_protection'],
		'S_OUTPUT_19'  => $configuration['reg_blocktime'],
		'S_OUTPUT_22'  => $configuration['pw_control'],
		'S_OUTPUT_23'  => $configuration['pw_validity'],
		'S_OUTPUT_24'  => $configuration['pw_complex'],
		'S_OUTPUT_25'  => $configuration['pw_complex_mode'],
		'S_OUTPUT_26'  => $configuration['pw_complex_min'],
		'S_OUTPUT_27'  => $configuration['pw_reset_feature'],
		'S_OUTPUT_28'  => $configuration['pwreset_time'],
		'S_OUTPUT_29'  => $configuration['massmail_protection'],
		'S_OUTPUT_30'  => $configuration['massmail_time'],
		'S_OUTPUT_31'  => $configuration['auto_recovery'],
		'S_OUTPUT_32'  => $configuration['vconfirm_guest'],
		'S_OUTPUT_33'  => $configuration['autoban_mails'],
		'S_OUTPUT_34'  => $configuration['detect_misconfiguration'],
		'S_OUTPUT_35'  => $configuration['spam_attack_boost'],
		'S_OUTPUT_36'  => $configuration['spam_keyword_det'],
		'S_OUTPUT_37'  => $configuration['request_limit_enabled'],
		'S_OUTPUT_38'  => $configuration['request_limit_login'],
		'S_OUTPUT_39'  => $configuration['request_limit_register'],
		'S_OUTPUT_40'  => $configuration['request_limit_write'],
		'S_OUTPUT_41'  => $configuration['request_limit_upload'],
		'S_OUTPUT_42'  => $configuration['request_limit_account'],
		
		'S_FORM_TOKEN' => phpbb_admin_session_field(),
		'S_FORM_ACTION' => append_sid('admin_cracker_tracker.' . $phpEx . '?modu=9'))
	);

// Generate the page
$template->pparse('ct_body');


?>
