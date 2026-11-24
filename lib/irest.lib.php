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
 * \file    lib/irest.lib.php
 * \ingroup irest
 * \brief   Functions of the module iRest for Dolibarr.
 */

/**
 * Tabs of the setup pages of the module.
 *
 * @return array<int,array<int,string>> Tabs
 */
function im_rst_admin_prepare_head()
{
	global $langs, $conf;

	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/irest/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;
	$head[$h][0] = dol_buildpath('/irest/admin/prerequisites.php', 1);
	$head[$h][1] = $langs->trans('IM_RST_Preparation');
	$head[$h][2] = 'prerequisites';
	$h++;
	$head[$h][0] = dol_buildpath('/irest/admin/install.php', 1);
	$head[$h][1] = $langs->trans('IM_RST_Installation');
	$head[$h][2] = 'install';
	$h++;
	complete_head_from_modules($conf, $langs, null, $head, $h, 'irest@irest');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'irest@irest', 'remove');

	return $head;
}

/**
 * Hours in the number format of the user ("1,5").
 *
 * @param  string|float $hours Hours as given by i-REST
 * @return string
 */
function im_rst_format_hours($hours)
{
	return price(price2num($hours), 0, '', 0, -1, -1);
}

/**
 * Hours left of a bank of hours, highlighted by the level given by i-REST (nothing left, few hours left).
 *
 * @param  array<string,mixed> $bank  Bank of hours as given by i-REST
 * @param  Translate           $langs Translations
 * @return string                     HTML
 */
function im_rst_format_balance($bank, $langs)
{
	$level = isset($bank['level']) ? (string) $bank['level'] : 'ok';
	if ($level == 'used_up') {
		return dolGetBadge(im_rst_format_hours($bank['balance']), '', 'danger', '', '', array('attr' => array('title' => $langs->trans('IM_RST_LevelUsedUp'))));
	}
	if ($level == 'low') {
		return dolGetBadge(im_rst_format_hours($bank['balance']), '', 'warning', '', '', array('attr' => array('title' => $langs->trans('IM_RST_LevelLow'))));
	}

	return '<strong>'.im_rst_format_hours($bank['balance']).'</strong>';
}
