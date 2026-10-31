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
 * \file    admin/prerequisites.php
 * \ingroup irest
 * \brief   Preparation of Dolibarr: prerequisites of the workflows of i-REST, creation of those missing.
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
dol_include_once('/irest/class/imrstprerequisites.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'categories', 'irest@irest'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$client = new ImRstClient();
$prerequisites = new ImRstPrerequisites($db, $client);


/*
 * Actions
 */

if ($action == 'create') {
	$codes = array();
	foreach ((array) GETPOST('codes', 'array') as $code) {
		$codes[] = (string) $code;
	}
	$choices = array();
	foreach ((array) GETPOST('choices', 'array') as $code => $values) {
		$choices[(string) $code] = array_map('strval', (array) $values);
	}
	$result = $prerequisites->create($user, $langs, $codes, $choices);
	if ($result === null) {
		setEventMessages($client->errorMessage($langs), null, 'errors');
	} else {
		if (!empty($result['created'])) {
			setEventMessages($langs->trans('IM_RST_Created', implode(', ', $result['created'])), null, 'mesgs');
		}
		if (!empty($result['errors'])) {
			setEventMessages($langs->trans('IM_RST_NotCreated', implode(', ', $result['errors'])), null, 'errors');
		}
		if (empty($result['created']) && empty($result['errors'])) {
			setEventMessages($langs->trans('IM_RST_NothingCreated'), null, 'warnings');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$states = $prerequisites->check($langs);

llxHeader('', $langs->trans('IM_RST_SetupTitle'), '', '', 0, 0, '', '', '', 'mod-irest page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('IM_RST_SetupTitle'), $linkback, 'title_setup');

print dol_get_fiche_head(im_rst_admin_prepare_head(), 'prerequisites', '', -1, 'fa-hourglass-half');

print '<span class="opacitymedium">'.$langs->trans('IM_RST_PreparationHelp').'</span><br><br>';

if ($states === null) {
	print info_admin($client->errorMessage($langs), 0, 0, 'warning');
} elseif (empty($states)) {
	print '<span class="opacitymedium">'.$langs->trans('IM_RST_NothingNeeded').'</span>';
} else {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="create">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="width25"></td><td>'.$langs->trans('IM_RST_Element').'</td><td class="center">'.$langs->trans('IM_RST_Required').'</td>';
	print '<td>'.$langs->trans('Status').'</td><td>'.$langs->trans('IM_RST_Choices').'</td></tr>';
	foreach ($states as $state) {
		$code = (string) $state['prerequisite']['code'];
		print '<tr class="oddeven">';
		print '<td>';
		if ($state['status'] == 'missing') {
			print '<input type="checkbox" name="codes[]" value="'.dol_escape_htmltag($code).'" checked>';
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag($state['label']).'</td>';
		print '<td class="center">'.yn(!empty($state['prerequisite']['required'])).'</td>';
		print '<td>';
		$badges = array('present' => array('status4', 'IM_RST_Present'), 'missing' => array('status8', 'IM_RST_Missing'), 'different' => array('status1', 'IM_RST_Different'));
		$badge = $badges[$state['status']] ?? $badges['different'];
		print dolGetBadge($langs->trans($badge[1]), '', $badge[0]);
		if (!empty($state['detail'])) {
			print '<br><span class="opacitymedium small">'.dol_escape_htmltag($state['detail']).'</span>';
		}
		print '</td>';
		print '<td>';
		if ($state['status'] == 'missing') {
			foreach ((array) ($state['prerequisite']['choices'] ?? array()) as $choice) {
				if (($choice['name'] ?? '') == 'type') {
					print '<select name="choices['.dol_escape_htmltag($code).'][type]" class="flat">';
					foreach ((array) $choice['values'] as $value) {
						$labelKey = $value == 'supplier' ? 'SuppliersCategoriesShort' : 'CustomersProspectsCategoriesShort';
						print '<option value="'.dol_escape_htmltag($value).'"'.($value == $choice['default'] ? ' selected' : '').'>'.$langs->trans($labelKey).'</option>';
					}
					print '</select>';
				}
			}
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
	print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('IM_RST_CreateTicked')).'"></div>';
	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
