#!/usr/bin/env php
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
 * \file    scripts/irest_install.php
 * \ingroup irest
 * \brief   Installation of i-REST in Dolibarr from the command line, for the packages. To run as the user of the site.
 *
 * Exit codes: 0 everything is in place, 1 something is missing, 2 waiting for i-REST (no address given), 3 i-REST
 * cannot be reached.
 */

if (PHP_SAPI !== 'cli') {
	echo "This script runs from the command line only.\n";
	exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	fwrite(STDERR, "Do not run this script as root: run it as the user of the site.\n");
	exit(1);
}

// From the path as called, not __DIR__: the module may be a symbolic link inside htdocs/custom
$root = dirname((string) $_SERVER['SCRIPT_FILENAME'], 4);
if (!is_file($root.'/master.inc.php')) {
	fwrite(STDERR, "Run this script from its place in htdocs/custom/irest/scripts.\n");
	exit(1);
}
define('NOSESSION', '1');
require_once $root.'/master.inc.php';
dol_include_once('/irest/class/imrstclient.class.php');
dol_include_once('/irest/class/imrstprerequisites.class.php');
dol_include_once('/irest/class/imrstinstaller.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$options = getopt('', array('write', 'read-only', 'generate-token', 'irest-url:', 'irest-key:', 'format:'));
$format = (string) ($options['format'] ?? 'text');
if (!in_array($format, array('text', 'json'), true) || (isset($options['write']) && isset($options['read-only']))) {
	fwrite(STDERR, "Usage: irest_install.php [--write|--read-only] [--generate-token] [--irest-url=URL --irest-key=KEY] [--format=text|json]\n");
	exit(1);
}
$langs->loadLangs(array('admin', 'irest@irest'));

$installer = new ImRstInstaller($db);
$installed = $installer->install(isset($options['read-only']));
$output = array('installed' => $installed, 'errors' => array_map(array($langs, 'transnoentitiesnoconv'), $installer->errors));
if (isset($options['generate-token']) && $installed) {
	$key = $installer->generateKey();
	if ($key === null) {
		$installed = false;
		$output['errors'][] = $langs->transnoentitiesnoconv('IM_RST_KeyNotGenerated');
	} else {
		$output['api_key'] = $key;
	}
}

$exit = $installed ? 0 : 1;
if (isset($options['irest-url'])) {
	$saved = ImRstClient::saveSetup($db, (string) $options['irest-url'], (string) ($options['irest-key'] ?? ''), getDolGlobalInt('IM_RST_TIMEOUT', ImRstClient::DEFAULT_TIMEOUT));
	$client = new ImRstClient();
	if ($saved < 0 || $client->status() === null) {
		$output['irest'] = $saved < 0 ? 'invalid' : 'unreachable';
		$exit = 3;
	} else {
		$prerequisites = new ImRstPrerequisites($db, $client);
		$result = $prerequisites->createMissing($user, $langs);
		$output['irest'] = 'ok';
		$output['prerequisites'] = $result;
		if ($result === null) {
			$exit = 3;
		} elseif (!empty($result['errors'])) {
			$exit = 1;
		}
	}
} elseif ($exit === 0) {
	$exit = 2;
}

$output['states'] = array();
foreach ((array) $installer->states($langs) as $state) {
	$output['states'][$state['code']] = $state['status'];
	if ($exit === 0 && in_array($state['status'], array('missing', 'foreign', 'unavailable'), true)) {
		$exit = 1;
	}
}

if ($format === 'json') {
	echo json_encode($output, JSON_UNESCAPED_SLASHES)."\n";
} else {
	foreach ($output['states'] as $code => $status) {
		echo str_pad($code, 40).$status."\n";
	}
	foreach ($output['errors'] as $error) {
		echo 'Error: '.$error."\n";
	}
	if (isset($output['api_key'])) {
		echo 'API key (shown once): '.$output['api_key']."\n";
	}
}
$db->close();
exit($exit);
