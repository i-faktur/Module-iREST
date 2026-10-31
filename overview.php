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
 * \file    overview.php
 * \ingroup irest
 * \brief   Banks of hours of all the customers (menu Third parties > Banks of hours), as i-REST computes them.
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/irest/lib/irest.lib.php');
dol_include_once('/irest/class/imrstclient.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('companies', 'contracts', 'irest@irest'));

// Only the internal users allowed to see the banks of hours of all the customers
if (!$user->hasRight('irest', 'hoursbank', 'readall') || !$user->hasRight('societe', 'lire') || $user->socid > 0) {
	accessforbidden();
}

$currentOnly = GETPOSTISSET('current') ? (GETPOSTINT('current') == 1) : true;
$sort = GETPOST('sort', 'aZ09') == 'balance' ? 'balance' : 'name';


/*
 * Data: one row by bank of hours (one row with "error" for a customer whose contracts cannot be read)
 */

$client = new ImRstClient();
$answer = $client->hoursBanksOverview();
$rows = array();
if ($answer !== null) {
	$today = dol_print_date(dol_now(), '%Y-%m-%d');
	foreach ((array) $answer['customers'] as $customer) {
		$row = array('customer' => $customer, 'bank' => null, 'left' => INF);
		if (!empty($customer['error'])) {
			$rows[] = $row;
			continue;
		}
		foreach ((array) $customer['banks'] as $bank) {
			if ($currentOnly && ($bank['line']['status'] == 'closed' || (!empty($bank['end']) && $bank['end'] < $today))) {
				continue;
			}
			$rows[] = array('bank' => $bank, 'left' => (float) $bank['balance']) + $row;
		}
	}
	if ($sort == 'balance') {
		usort($rows, function ($first, $second) {
			return $first['left'] <=> $second['left'];
		});
	}
}


/*
 * View
 */

llxHeader('', $langs->trans('IM_RST_HoursBanks'), '', '', 0, 0, '', '', '', 'mod-irest page-overview');

print load_fiche_titre($langs->trans('IM_RST_HoursBanks'), '', 'fa-hourglass-half');
print '<span class="opacitymedium">'.$langs->trans('IM_RST_AllCustomersHelp').'</span><br><br>';

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="current" value="0">';
print '<label class="marginrightonly"><input type="checkbox" name="current" value="1"'.($currentOnly ? ' checked' : '').' onchange="this.form.submit()"> '.$langs->trans('IM_RST_CurrentOnly').'</label>';
print '<label class="marginleftonly">'.$langs->trans('IM_RST_SortBy').' ';
print '<select name="sort" class="flat" onchange="this.form.submit()">';
print '<option value="name"'.($sort == 'name' ? ' selected' : '').'>'.$langs->trans('ThirdParty').'</option>';
print '<option value="balance"'.($sort == 'balance' ? ' selected' : '').'>'.$langs->trans('IM_RST_SortLeft').'</option>';
print '</select></label>';
print '</form><br>';

if ($answer === null) {
	print info_admin($client->errorMessage($langs), 0, 0, 'warning');
} else {
	if (!empty($answer['error'])) {
		print info_admin($langs->trans('IM_RST_ErrorUnavailable'), 0, 0, 'warning');
	}
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('ThirdParty').'</td><td>'.$langs->trans('IM_RST_GlpiEntity').'</td>';
	print '<td>'.$langs->trans('Contract').'</td><td>'.$langs->trans('IM_RST_Period').'</td>';
	print '<td class="right">'.$langs->trans('IM_RST_Bought').'</td><td class="right">'.$langs->trans('IM_RST_Carried').'</td>';
	print '<td class="right">'.$langs->trans('IM_RST_Used').'</td><td class="right">'.$langs->trans('IM_RST_Left').'</td>';
	print '<td>'.$langs->trans('IM_RST_EndOfPeriod').'</td>';
	print '</tr>';
	if (empty($rows)) {
		print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('IM_RST_NoBank').'</span></td></tr>';
	}
	foreach ($rows as $row) {
		$customer = $row['customer'];
		$bank = $row['bank'];
		$customerUrl = dol_buildpath('/irest/hoursbanks.php', 1).'?socid='.((int) $customer['thirdPartyId']);
		print '<tr class="oddeven'.($bank !== null && $bank['line']['status'] == 'closed' ? ' opacitymedium' : '').'">';
		print '<td class="tdoverflowmax200"><a href="'.$customerUrl.'">'.dol_escape_htmltag((string) $customer['name']).'</a></td>';
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag((string) $customer['entityName']).'</td>';
		if ($bank === null) {
			print '<td colspan="7"><span class="warning">'.$langs->trans('IM_RST_ErrorUnavailable').'</span></td>';
			print '</tr>';
			continue;
		}
		$contractUrl = DOL_URL_ROOT.'/contrat/card.php?id='.((int) $bank['contract']['id']);
		print '<td><a href="'.$contractUrl.'">'.dol_escape_htmltag((string) $bank['contract']['ref']).'</a>';
		if ($bank['line']['status'] == 'closed') {
			print ' <span class="small">('.$langs->trans('IM_RST_Closed').')</span>';
		}
		print '</td>';
		print '<td>'.($bank['start'] ? dol_print_date(dol_stringtotime($bank['start']), 'day') : '…').' – '.($bank['end'] ? dol_print_date(dol_stringtotime($bank['end']), 'day') : '…').'</td>';
		print '<td class="right">'.im_rst_format_hours($bank['bought']).'</td>';
		print '<td class="right">'.im_rst_format_hours($bank['carried']).'</td>';
		print '<td class="right">'.im_rst_format_hours($bank['taken']).'</td>';
		print '<td class="right">'.im_rst_format_balance($bank, $langs).'</td>';
		print '<td>'.$langs->trans($bank['rollover'] == 'carry' ? 'IM_RST_RolloverCarry' : ($bank['rollover'] == 'lose' ? 'IM_RST_RolloverLose' : 'IM_RST_RolloverNone')).'</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

llxFooter();
$db->close();
