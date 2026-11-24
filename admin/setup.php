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
 * \file    admin/setup.php
 * \ingroup irest
 * \brief   Setup of the module iRest for Dolibarr: mode, then address of i-REST, key of its API, time-out and test
 *          (mode "read"), or state of the data written by i-REST (mode "push").
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
dol_include_once('/irest/class/imrstclient.class.php');
dol_include_once('/irest/class/imrstdocument.class.php');
dol_include_once('/irest/class/imrstprerequisites.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'irest@irest'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');


/*
 * Actions
 */

if ($action == 'mode') {
	if (ImRstClient::saveMode($db, GETPOST('IM_RST_MODE', 'aZ09')) > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'save' || $action == 'test') {
	if ($action == 'save') {
		$result = ImRstClient::saveSetup($db, GETPOST('IM_RST_API_URL', 'alphanohtml'), GETPOST('IM_RST_API_KEY', 'none'), GETPOSTINT('IM_RST_TIMEOUT'));
		if ($result == -1) {
			setEventMessages($langs->trans('IM_RST_ErrorUrl'), null, 'errors');
		} elseif ($result == -2) {
			setEventMessages($langs->trans('IM_RST_ErrorTimeout'), null, 'errors');
		} else {
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		}
	}
	$client = new ImRstClient();
	$status = $client->status();
	if ($status !== null) {
		setEventMessages($langs->trans($client->mode() === ImRstClient::MODE_PUSH ? 'IM_RST_PushOk' : 'IM_RST_StatusOk', (string) ($status['connection']['label'] ?? '?')), null, 'mesgs');
		// The configuration works: what the workflows need is created without a click (nothing that exists is changed)
		if ($action == 'save' || $client->mode() === ImRstClient::MODE_PUSH) {
			$created = (new ImRstPrerequisites($db, $client))->createMissing($user, $langs);
			if ($created === null) {
				setEventMessages($client->errorMessage($langs), null, 'warnings');
			} else {
				if (!empty($created['created'])) {
					setEventMessages($langs->trans('IM_RST_Created', implode(', ', $created['created'])), null, 'mesgs');
				}
				if (!empty($created['errors'])) {
					setEventMessages($langs->trans('IM_RST_NotCreated', implode(', ', $created['errors'])), null, 'errors');
				}
			}
		}
	} else {
		setEventMessages($client->errorMessage($langs), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$client = new ImRstClient();

llxHeader('', $langs->trans('IM_RST_SetupTitle'), '', '', 0, 0, '', '', '', 'mod-irest page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('IM_RST_SetupTitle'), $linkback, 'title_setup');

print dol_get_fiche_head(im_rst_admin_prepare_head(), 'settings', '', -1, 'fa-hourglass-half');

print '<span class="opacitymedium">'.$langs->trans('IM_RST_SetupHelp').'</span><br><br>';

// Mode: read the API of i-REST, or read the data i-REST writes here
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="mode">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('IM_RST_Mode').'</td></tr>';
foreach (array(ImRstClient::MODE_READ => 'IM_RST_ModeRead', ImRstClient::MODE_PUSH => 'IM_RST_ModePush') as $mode => $labelKey) {
	print '<tr class="oddeven"><td class="width25"><input type="radio" id="im_rst_mode_'.$mode.'" name="IM_RST_MODE" value="'.$mode.'"'.($client->mode() === $mode ? ' checked' : '').'></td>';
	print '<td><label for="im_rst_mode_'.$mode.'">'.$langs->trans($labelKey).'</label><br><span class="opacitymedium">'.$langs->trans($labelKey.'Help').'</span></td></tr>';
}
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form><br>';

if ($client->mode() === ImRstClient::MODE_PUSH) {
	$lastWrite = ImRstDocument::lastWrite($db);
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('IM_RST_LastWrite').'</td>';
	print '<td>'.($lastWrite !== null ? dol_print_date($lastWrite, 'dayhour', 'tzuserrel') : '<span class="opacitymedium">'.$langs->trans('IM_RST_NothingWritten').'</span>').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('IM_RST_ApiUser').'</td>';
	print '<td><span class="opacitymedium">'.$langs->trans('IM_RST_ApiUserHelp').'</span></td></tr>';
	print '</table>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="center">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="test">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('IM_RST_TestPush')).'">';
	print '</form>';

	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('IM_RST_ApiUrl').'</td>';
print '<td><input type="url" class="minwidth400" name="IM_RST_API_URL" value="'.dol_escape_htmltag(getDolGlobalString('IM_RST_API_URL')).'" placeholder="https://irest.example.com"></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('IM_RST_ApiKey').'</td>';
print '<td><input type="password" class="minwidth400" name="IM_RST_API_KEY" value="" autocomplete="off"> ';
print '<span class="opacitymedium">'.$langs->trans($client->hasKey() ? 'IM_RST_KeySaved' : 'IM_RST_NoKey').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('IM_RST_Timeout').'</td>';
print '<td><input type="number" class="width75" min="1" max="120" name="IM_RST_TIMEOUT" value="'.getDolGlobalInt('IM_RST_TIMEOUT', ImRstClient::DEFAULT_TIMEOUT).'"></td></tr>';
print '</table>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
print '</div>';
print '</form>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="center">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('IM_RST_Test')).'">';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
