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
 * \file    hoursbanks.php
 * \ingroup irest
 * \brief   Tab "Banks of hours" of a third party (socid) or a contract (id), as i-REST computes them.
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

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/contract.lib.php';
dol_include_once('/irest/lib/irest.lib.php');
dol_include_once('/irest/class/imrstclient.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('companies', 'contracts', 'bills', 'irest@irest'));

$socid = GETPOSTINT('socid');
$id = GETPOSTINT('id');

if (!$user->hasRight('irest', 'hoursbank', 'read')) {
	accessforbidden();
}

$contract = null;
$thirdparty = new Societe($db);
if ($id > 0) {
	$contract = new Contrat($db);
	if ($contract->fetch($id) <= 0) {
		accessforbidden();
	}
	restrictedArea($user, 'contrat', $contract->id);
	$socid = (int) $contract->socid;
} else {
	restrictedArea($user, 'societe', $socid, '&societe');
}
if ($thirdparty->fetch($socid) <= 0) {
	accessforbidden();
}


/*
 * View
 */

$client = new ImRstClient();
$answer = $client->hoursBanks($thirdparty->id, $contract !== null ? $contract->id : null);

llxHeader('', $langs->trans('IM_RST_HoursBanks'), '', '', 0, 0, '', '', '', 'mod-irest page-hoursbanks');

if ($contract !== null) {
	$contract->fetch_thirdparty();
	print dol_get_fiche_head(contract_prepare_head($contract), 'irest', $langs->trans('Contract'), -1, 'contract');
	$linkback = '<a href="'.DOL_URL_ROOT.'/contrat/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
	dol_banner_tab($contract, 'id', $linkback, 1, 'rowid', 'ref', '');
} else {
	print dol_get_fiche_head(societe_prepare_head($thirdparty), 'irest', $langs->trans('ThirdParty'), -1, 'company');
	$linkback = '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
	dol_banner_tab($thirdparty, 'socid', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');
}
print '<div class="clearboth"></div>';
print dol_get_fiche_end();

print '<span class="opacitymedium">'.$langs->trans('IM_RST_HoursBanksHelp').'</span><br><br>';

if ($answer === null) {
	print info_admin($client->errorMessage($langs), 0, 0, 'warning');
} else {
	foreach ((array) $answer['customers'] as $customer) {
		print load_fiche_titre($langs->trans('IM_RST_LinkedEntity', dol_escape_htmltag((string) $customer['entityName'])), '', '');
		if (!empty($customer['error'])) {
			print info_admin($langs->trans('IM_RST_ErrorUnavailable'), 0, 0, 'warning');
			continue;
		}
		if (empty($customer['banks'])) {
			print '<span class="opacitymedium">'.$langs->trans('IM_RST_NoBank').'</span><br><br>';
			continue;
		}
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Contract').'</td><td>'.$langs->trans('IM_RST_Period').'</td>';
		print '<td class="right">'.$langs->trans('IM_RST_Bought').'</td><td class="right">'.$langs->trans('IM_RST_Carried').'</td>';
		print '<td class="right">'.$langs->trans('IM_RST_Used').'</td><td class="right">'.$langs->trans('IM_RST_Left').'</td>';
		print '<td>'.$langs->trans('IM_RST_EndOfPeriod').'</td><td>'.$langs->trans('IM_RST_Scope').'</td>';
		print '</tr>';
		foreach ((array) $customer['banks'] as $bank) {
			$contractUrl = DOL_URL_ROOT.'/contrat/card.php?id='.((int) $bank['contract']['id']);
			print '<tr class="oddeven'.($bank['line']['status'] == 'closed' ? ' opacitymedium' : '').'">';
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
			print '<td>'.$langs->trans($bank['rollover'] == 'carry' ? 'IM_RST_RolloverCarry' : ($bank['rollover'] == 'lose' ? 'IM_RST_RolloverLose' : 'IM_RST_RolloverNone'));
			if (!empty($bank['rolloverMissing'])) {
				print '<br><span class="warning small">'.$langs->trans('IM_RST_RolloverMissing').'</span>';
			}
			print '</td>';
			print '<td>'.$langs->trans($bank['scope'] == 'all' ? 'IM_RST_ScopeAll' : 'IM_RST_ScopeExceptExcluded').'</td>';
			print '</tr>';
		}
		print '</table>';
		print '</div>';

		print load_fiche_titre($langs->trans('IM_RST_TasksTaken'), '', '');
		if (empty($customer['tickets'])) {
			print '<span class="opacitymedium">'.$langs->trans('IM_RST_NoTaskTaken').'</span><br><br>';
			continue;
		}
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><td>'.$langs->trans('IM_RST_BilledOn').'</td><td>'.$langs->trans('IM_RST_Ticket').'</td>';
		print '<td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('IM_RST_Hours').'</td><td>'.$langs->trans('Invoice').'</td></tr>';
		foreach ((array) $customer['tickets'] as $ticket) {
			print '<tr class="oddeven">';
			print '<td>'.dol_print_date(dol_stringtotime((string) $ticket['billedAt']), 'day').'</td>';
			print '<td>#'.dol_escape_htmltag((string) $ticket['ticket']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $ticket['label']).'</td>';
			print '<td class="right">'.im_rst_format_hours($ticket['hours']).'</td>';
			print '<td>';
			if (!empty($ticket['invoice'])) {
				print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?ref='.urlencode((string) $ticket['invoice']).'">'.dol_escape_htmltag((string) $ticket['invoice']).'</a>';
			}
			print '</td>';
			print '</tr>';
		}
		print '</table>';
		print '</div>';
	}
}

llxFooter();
$db->close();
