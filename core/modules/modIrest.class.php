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
 * \file    core/modules/modIrest.class.php
 * \ingroup irest
 * \brief   Descriptor of the module iRest for Dolibarr, companion of the i-REST application.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descriptor of the module iRest for Dolibarr (number 407006, prefix IM_RST): tab "Banks of hours" of the third
 * parties and contracts, filled from the API of i-REST, and preparation of Dolibarr for the workflows of i-REST.
 * The module computes nothing: what it shows and what it creates comes from i-REST.
 */
class modIrest extends DolibarrModules
{
	/**
	 * Declares the module.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 407006;
		$this->rights_class = 'irest';
		$this->family = 'I-Motion';
		$this->module_position = '90';
		$this->name = 'iRest';
		$this->description = 'IM_RST_ModuleDescription';
		$this->descriptionlong = 'IM_RST_ModuleDescription';
		$this->editor_name = 'I-Motion SARL';
		$this->editor_url = 'https://www.i-motion.fr';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-hourglass-half';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'theme' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@irest');

		$this->hidden = false;
		$this->depends = array('modSociete', 'modContrat');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('irest@irest');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(23, 0);
		$this->need_javascript_ajax = 0;

		// Configuration (setup page): address of i-REST, key of its API (encrypted), time-out
		$this->const = array();

		// Tab "Banks of hours" of the third parties and contracts, for the users allowed to see them
		$this->tabs = array(
			array('data' => 'thirdparty:+irest:IM_RST_HoursBanks:irest@irest:$user->hasRight("irest", "hoursbank", "read"):/irest/hoursbanks.php?socid=__ID__'),
			array('data' => 'contract:+irest:IM_RST_HoursBanks:irest@irest:$user->hasRight("irest", "hoursbank", "read"):/irest/hoursbanks.php?id=__ID__'),
		);

		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', 1);
		$this->rights[$r][1] = 'IM_RST_RightReadHoursBanks';
		$this->rights[$r][4] = 'hoursbank';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', 2);
		$this->rights[$r][1] = 'IM_RST_RightReadAllHoursBanks';
		$this->rights[$r][4] = 'hoursbank';
		$this->rights[$r][5] = 'readall';
		$r++;

		// Entry "Banks of hours" of the menu of the third parties: banks of hours of all the customers
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=companies',
			'type' => 'left',
			'titre' => 'IM_RST_HoursBanks',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'companies',
			'leftmenu' => 'irest_hoursbanks',
			'url' => '/irest/overview.php',
			'langs' => 'irest@irest',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("irest")',
			'perms' => '$user->hasRight("irest", "hoursbank", "readall")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Enables the module: rights and constants (no table, no extra field of its own; the extra fields the workflows
	 * of i-REST need are created from the preparation page).
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, <= 0 if KO
	 */
	public function init($options = '')
	{
		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Disables the module (the configuration is kept).
	 *
	 * @param  string $options Options when disabling module ('', 'noboxes')
	 * @return int             1 if OK, <= 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
