<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Read declaration metadata, never include an active ACP controller to discover
// its permissions. Re-including one can redeclare functions or execute startup.
function jr_admin_registration_value($tokens, $file)
{
	global $phpEx;
	$value = ''; $expect_atom = true; $quoted = false;
	foreach ($tokens as $token)
	{
		if ($quoted)
		{
			if ($token === '"') { $quoted = false; $expect_atom = false; }
			elseif (is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE) { $value .= $token[1]; }
			elseif (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$phpEx') { $value .= $phpEx; }
			else { return false; }
		}
		elseif (!$expect_atom)
		{
			if ($token !== '.') { return false; }
			$expect_atom = true;
		}
		elseif ($token === '"') { $quoted = true; }
		elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING)
		{
			// Current registrations use plain route literals, no escapes/code.
			$text = substr($token[1], 1, -1);
			if (strpos($text, '\\') !== false) { return false; }
			$value .= $text; $expect_atom = false;
		}
		elseif (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], array('$file', '$filename', '$phpEx'), true))
		{
			$value .= $token[1] === '$phpEx' ? $phpEx : $file; $expect_atom = false;
		}
		else { return false; }
	}
	return !$quoted && !$expect_atom ? $value : false;
}

function jr_admin_file_registrations($source, $file)
{
	$tokens = array(); $registrations = array();
	foreach (token_get_all($source) as $token)
	{
		if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) { continue; }
		$tokens[] = $token;
	}
	$count = count($tokens);
	for ($i = 0; $i < $count; $i++)
	{
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$module') { continue; }
		if ($i + 7 >= $count || $tokens[$i+1] !== '[' || $tokens[$i+3] !== ']' || $tokens[$i+4] !== '[' || $tokens[$i+6] !== ']' || $tokens[$i+7] !== '=') { return false; }
		$category = jr_admin_registration_value(array($tokens[$i+2]), $file);
		$name = jr_admin_registration_value(array($tokens[$i+5]), $file);
		$expression = array(); $i += 8;
		while ($i < $count && $tokens[$i] !== ';') { $expression[] = $tokens[$i++]; }
		$route = jr_admin_registration_value($expression, $file);
		if ($category === false || $name === false || $route === false || $i === $count) { return false; }
		$registrations[] = array($category, $name, $route);
	}
	return $registrations;
}

function jr_admin_authorization_routes()
{
	global $phpEx;
	static $routes = null;
	if ($routes !== null) { return $routes; }
	$directory = jr_admin_module_directory();
	if ($directory === false) { return false; }
	$dir = @opendir($directory);
	if ($dir === false) { return false; }
	$found = array();
	try
	{
		while (($file = readdir($dir)) !== false)
		{
			if (!preg_match('/^admin_[a-z0-9_]+\.' . preg_quote($phpEx, '/') . '$/iD', $file)) { continue; }
			$source = @file_get_contents($directory . $file);
			if ($source === false) { return false; }
			$entries = jr_admin_file_registrations($source, $file);
			if ($entries === false) { return false; }
			foreach ($entries as $entry) { $found[md5($entry[0] . $entry[1] . $entry[2])] = $entry[2]; }
		}
	}
	finally { closedir($dir); }
	// eXtreme Styles registers its menu through xs_include rather than a literal
	// admin_ declaration. Keep its stable and localized menu identities confined
	// to the styles controller, never another ACP endpoint.
	$found[md5('StylesMenu' . 'xs_frameset.' . $phpEx . '?action=menu&showwarning=1')] = 'xs_frameset.' . $phpEx;
	$found[md5('Extreme_StylesStyles_Management' . 'xs_frameset.' . $phpEx . '?action=menu')] = 'xs_frameset.' . $phpEx;
	$actions = array('config','install','uninstall','default','cache','import','export','clone','download','edittpl','editdb','exportdb','updates');
	foreach (array('english','german') as $locale)
	{
		$lang = array();
		include dirname(__DIR__) . '/language/lang_' . $locale . '/lang_xs.php';
		foreach ($actions as $index => $action)
		{
			if ($index === 8 || $index === 9 || !isset($lang['xs_config_shownav'][$index])) { continue; }
			$found[md5('Extreme_Styles' . $lang['xs_config_shownav'][$index] . 'xs_frameset.' . $phpEx . '?action=' . $action)] = 'xs_frameset.' . $phpEx;
		}
	}
	$routes = $found;
	return $routes;
}

function jr_admin_route_matches_file($route, $file)
{
	global $phpEx;
	$target = explode('?', $route, 2); $target = $target[0];
	// Never collapse ../public.php to an ACP filename.
	if (!preg_match('/^(?:admin_[a-z0-9_]+|xs_frameset)\.' . preg_quote($phpEx, '/') . '$/iD', $target)) { return false; }
	if ($target === $file) { return true; }
	// Non-menu helper pages reached from these registered modules.
	$parents = array('admin_arcade_scores.' . $phpEx => 'admin_arcade_games.' . $phpEx,
		'admin_pa_ug_auth.' . $phpEx => 'admin_pa_catauth.' . $phpEx);
	foreach (array('xs_chmod','xs_uninstall','xs_cache','xs_config','xs_export','xs_edit','xs_clone','xs_style_config','xs_index','xs_install','xs_edit_data','xs_import','xs_frame_top','xs_styles','xs_export_data') as $helper)
	{
		$parents[$helper . '.' . $phpEx] = 'xs_frameset.' . $phpEx;
	}
	return isset($parents[$file]) && $parents[$file] === $target;
}
