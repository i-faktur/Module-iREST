<?php
/* Copyright (C) 2026 I-Motion SARL
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    admin/install.php
 * \ingroup irest
 * \brief   Installation of i-REST in Dolibarr: state of the group, API user, rights and key, creation of what is missing.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/irest/lib/irest.lib.php');
dol_include_once('/irest/class/imrstinstaller.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'users', 'irest@irest'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$installer = new ImRstInstaller($db);
$generatedKey = null;


/*
 * Actions
 */

if ($action == 'install' || $action == 'installreadonly') {
	if ($installer->install($action == 'installreadonly')) {
		setEventMessages($langs->trans('IM_RST_InstallDone'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('IM_RST_InstallErrors'), array_map(array($langs, 'trans'), $installer->errors), 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'generatekey') {
	// Shown in this answer only, without a redirection: the key is never displayed again
	$generatedKey = $installer->generateKey();
	if ($generatedKey === null) {
		setEventMessages($langs->trans('IM_RST_KeyNotGenerated'), null, 'errors');
	}
}


/*
 * View
 */

if ($generatedKey !== null) {
	header('Cache-Control: no-store');
}

$states = $installer->states($langs);

llxHeader('', $langs->trans('IM_RST_SetupTitle'), '', '', 0, 0, '', '', '', 'mod-irest page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('IM_RST_SetupTitle'), $linkback, 'title_setup');

print dol_get_fiche_head(im_rst_admin_prepare_head(), 'install', '', -1, 'fa-hourglass-half');

print '<span class="opacitymedium">'.$langs->trans('IM_RST_InstallationHelp').'</span><br><br>';

if ($generatedKey !== null) {
	print info_admin($langs->trans('IM_RST_KeyOnce'), 0, 0, 'warning');
	print '<input type="text" class="minwidth400" readonly value="'.dol_escape_htmltag($generatedKey).'" autocomplete="off" onfocus="this.select()"><br><br>';
}

if ($states === null) {
	print info_admin($langs->trans('IM_RST_ManifestMissing'), 0, 0, 'warning');
} else {
	if (!$installer->isManaged() && !$installer->mayInstallAutomatically()) {
		print info_admin($langs->trans('IM_RST_NotManagedHelp'), 0, 0, 'warning');
	}
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('IM_RST_Element').'</td><td>'.$langs->trans('Status').'</td></tr>';
	$badges = array(
		'present' => array('status4', 'IM_RST_Present'),
		'missing' => array('status8', 'IM_RST_Missing'),
		'foreign' => array('status1', 'IM_RST_StateForeign'),
		'unavailable' => array('status1', 'IM_RST_StateUnavailable'),
	);
	foreach ($states as $state) {
		$badge = $badges[$state['status']];
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($state['label']).'</td><td>'.dolGetBadge($langs->trans($badge[1]), '', $badge[0]).'</td></tr>';
	}
	print '</table>';

	print '<div class="center">';
	foreach (array('install' => array('IM_RST_InstallRun', 'button'), 'installreadonly' => array('IM_RST_InstallRunReadOnly', 'button button-cancel')) as $name => $button) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="'.$name.'">';
		print '<input type="submit" class="'.$button[1].'" value="'.dol_escape_htmltag($langs->trans($button[0])).'">';
		print '</form> ';
	}
	if (getDolGlobalInt(ImRstInstaller::CONST_USER) > 0) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->transnoentitiesnoconv('IM_RST_GenerateKeyConfirm')).'\');">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="generatekey">';
		print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('IM_RST_GenerateKey')).'">';
		print '</form>';
	}
	print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
