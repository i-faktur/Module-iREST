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
 * \file    class/imrstclient.class.php
 * \ingroup irest
 * \brief   Client of the API of i-REST, or of the documents written by i-REST (mode "push").
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
dol_include_once('/irest/class/imrstdocument.class.php');

/**
 * Data of i-REST for the pages of the module, in the mode of the setup (constant IM_RST_MODE). Mode "read" (by
 * default): calls to the API of i-REST, server to server, with the key of the setup. Mode "push": the same answers,
 * read in the documents i-REST wrote in this Dolibarr (ImRstDocument), and the report of the prerequisites written
 * there for i-REST: Dolibarr never calls i-REST. Failures are kept as a short code in $error (not_configured,
 * not_pushed, report_not_written, unauthorized, unsupported_version, not_linked, unavailable, invalid_response); their
 * detail goes to the Dolibarr log, never the key.
 */
class ImRstClient
{
	/** Version of the API of i-REST this module speaks. */
	const API_VERSION = 1;

	/** Version of the module, sent to i-REST. */
	const MODULE_VERSION = '1.1.0';

	/** Mode "read": the module calls the API of i-REST. */
	const MODE_READ = 'read';

	/** Mode "push": i-REST writes its data in this Dolibarr, the module never calls i-REST. */
	const MODE_PUSH = 'push';

	/** Time-out of a call by default, in seconds. */
	const DEFAULT_TIMEOUT = 10;

	/**
	 * @var string Short code of the last failure, '' when the last call succeeded
	 */
	public $error = '';

	/**
	 * @var string Address of i-REST, without trailing slash
	 */
	private $url;

	/**
	 * @var string Key of the API of i-REST
	 */
	private $apiKey;

	/**
	 * @var int Time-out of a call, in seconds
	 */
	private $timeout;

	/**
	 * @var string Mode of the setup, MODE_READ or MODE_PUSH
	 */
	private $mode;

	/**
	 * Client of the setup of the module (constants of the current entity).
	 */
	public function __construct()
	{
		$this->url = rtrim(getDolGlobalString('IM_RST_API_URL'), '/');
		$stored = getDolGlobalString('IM_RST_API_KEY');
		$this->apiKey = $stored !== '' ? (string) dolDecrypt($stored) : '';
		$this->timeout = max(1, getDolGlobalInt('IM_RST_TIMEOUT', self::DEFAULT_TIMEOUT));
		$this->mode = getDolGlobalString('IM_RST_MODE') === self::MODE_PUSH ? self::MODE_PUSH : self::MODE_READ;
	}

	/**
	 * Mode of the setup.
	 *
	 * @return string MODE_READ or MODE_PUSH
	 */
	public function mode()
	{
		return $this->mode;
	}

	/**
	 * Saves the mode of the module.
	 *
	 * @param  DoliDB $db   Database handler
	 * @param  string $mode MODE_READ or MODE_PUSH
	 * @return int          1 if OK, -1 if the mode is not valid
	 */
	public static function saveMode($db, $mode)
	{
		global $conf;

		if (!in_array($mode, array(self::MODE_READ, self::MODE_PUSH), true)) {
			return -1;
		}
		dolibarr_set_const($db, 'IM_RST_MODE', $mode, 'chaine', 0, '', $conf->entity);

		return 1;
	}

	/**
	 * Saves the setup of the module; a blank key keeps the current one.
	 *
	 * @param  DoliDB $db      Database handler
	 * @param  string $url     Address of i-REST
	 * @param  string $apiKey  Key of the API of i-REST, '' to keep the current one
	 * @param  int    $timeout Time-out of a call, in seconds (1 to 120)
	 * @return int             1 if OK, -1 if the address is not valid, -2 if the time-out is not valid
	 */
	public static function saveSetup($db, $url, $apiKey, $timeout)
	{
		global $conf;

		$url = rtrim(trim($url), '/');
		if ($url !== '' && !preg_match('#^https?://[^\s/]+#', $url)) {
			return -1;
		}
		if ($timeout < 1 || $timeout > 120) {
			return -2;
		}
		dolibarr_set_const($db, 'IM_RST_API_URL', $url, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'IM_RST_TIMEOUT', (string) $timeout, 'chaine', 0, '', $conf->entity);
		if (trim($apiKey) !== '') {
			dolibarr_set_const($db, 'IM_RST_API_KEY', dolEncrypt(trim($apiKey)), 'chaine', 0, '', $conf->entity);
		}

		return 1;
	}

	/**
	 * Whether a key is saved.
	 *
	 * @return bool
	 */
	public function hasKey()
	{
		return $this->apiKey !== '';
	}

	/**
	 * Version of the API and connection of the key.
	 *
	 * @return array<string,mixed>|null Answer, null on failure (see $error)
	 */
	public function status()
	{
		$status = $this->call('GET', 'status');
		if ($status !== null && ($status['api'] ?? null) !== self::API_VERSION) {
			$this->error = 'unsupported_version';
			return null;
		}

		return $status;
	}

	/**
	 * Banks of hours of a third party, of one of its contracts only when given.
	 *
	 * @param  int      $thirdPartyId Id of the third party
	 * @param  int|null $contractId   Id of the contract, null for all
	 * @return array<string,mixed>|null Answer, null on failure (see $error)
	 */
	public function hoursBanks($thirdPartyId, $contractId = null)
	{
		$query = array('thirdparty' => (string) ((int) $thirdPartyId));
		if ($contractId !== null) {
			$query['contract'] = (string) ((int) $contractId);
		}

		return $this->call('GET', 'hours-banks', $query);
	}

	/**
	 * Banks of hours of all the customers, without the tasks.
	 *
	 * @return array<string,mixed>|null Answer, null on failure (see $error)
	 */
	public function hoursBanksOverview()
	{
		return $this->call('GET', 'hours-banks/overview');
	}

	/**
	 * Prerequisites of the workflows of i-REST in this Dolibarr.
	 *
	 * @return array<int,array<string,mixed>>|null List, null on failure (see $error)
	 */
	public function prerequisites()
	{
		$answer = $this->call('GET', 'prerequisites');
		if ($answer === null) {
			return null;
		}

		return is_array($answer['prerequisites'] ?? null) ? array_values($answer['prerequisites']) : array();
	}

	/**
	 * Reports the state of the prerequisites checked in this Dolibarr.
	 *
	 * @param  array<string,array{status:string,detail:string|null}> $states State by code
	 * @return bool                                                    Whether i-REST recorded it
	 */
	public function report($states)
	{
		return $this->call('POST', 'prerequisites/report', array(), array('prerequisites' => empty($states) ? new stdClass() : $states)) !== null;
	}

	/**
	 * Calls the API and decodes its JSON answer.
	 *
	 * @param  string                $method GET or POST
	 * @param  string                $path   Path after /api/v1/
	 * @param  array<string,string>  $query  Parameters of the URL
	 * @param  array<string,mixed>|null $body Body sent as JSON
	 * @return array<string,mixed>|null      Answer, null on failure (see $error)
	 */
	private function call($method, $path, $query = array(), $body = null)
	{
		$this->error = '';
		if ($this->mode === self::MODE_PUSH) {
			return $this->local($method, $path, $query, $body);
		}
		if ($this->url === '' || $this->apiKey === '') {
			$this->error = 'not_configured';
			return null;
		}
		$url = $this->url.'/api/v1/'.$path.(empty($query) ? '' : '?'.http_build_query($query));
		$headers = array(
			'Authorization: Bearer '.$this->apiKey,
			'Accept: application/json',
			'X-Irest-Client: irest-dolibarr/'.self::MODULE_VERSION,
		);
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}
		// i-REST may be on the local network (development) or on Internet (hosted): both are allowed (2)
		$result = getURLContent($url, $body !== null ? 'POSTALREADYFORMATED' : 'GET', $body !== null ? json_encode($body) : '', 0, $headers, array('http', 'https'), 2, -1, $this->timeout, $this->timeout);
		$code = (int) $result['http_code'];
		$answer = json_decode((string) $result['content'], true);
		if ($code === 0) {
			dol_syslog(__METHOD__.' '.$method.' '.$path.': '.$result['curl_error_msg'], LOG_WARNING);
			$this->error = 'unavailable';
			return null;
		}
		if ($code === 401) {
			$this->error = 'unauthorized';
			return null;
		}
		if ($code === 404 && is_array($answer) && ($answer['error'] ?? null) === 'not_linked') {
			$this->error = 'not_linked';
			return null;
		}
		if ($code !== 200 || !is_array($answer)) {
			dol_syslog(__METHOD__.' '.$method.' '.$path.': HTTP '.$code, LOG_WARNING);
			$this->error = $code >= 500 ? 'unavailable' : 'invalid_response';
			return null;
		}

		return $answer;
	}

	/**
	 * Answer of a call read in the documents written by i-REST (mode "push"); the report of the prerequisites is
	 * written as a document that i-REST reads back.
	 *
	 * @param  string                   $method GET or POST
	 * @param  string                   $path   Path after /api/v1/
	 * @param  array<string,string>     $query  Parameters of the URL
	 * @param  array<string,mixed>|null $body   Body of the call
	 * @return array<string,mixed>|null         Answer, null on failure (see $error)
	 */
	private function local($method, $path, $query, $body)
	{
		global $db;

		if ($method === 'POST' && $path === 'prerequisites/report') {
			if (ImRstDocument::save($db, ImRstDocument::PREREQUISITES_REPORT, (string) json_encode($body)) < 0) {
				$this->error = 'report_not_written';
				return null;
			}

			return array('recorded' => count((array) $body['prerequisites']));
		}
		$keys = array('status' => ImRstDocument::STATUS, 'prerequisites' => ImRstDocument::PREREQUISITES, 'hours-banks/overview' => ImRstDocument::HOURS_BANKS_OVERVIEW);
		$customer = $path === 'hours-banks';
		$key = $customer ? ImRstDocument::HOURS_BANKS_PREFIX.((int) ($query['thirdparty'] ?? 0)) : ($keys[$path] ?? '');
		$document = $key !== '' ? ImRstDocument::fetch($db, $key) : -1;
		if ($document === -1) {
			$this->error = 'invalid_response';
			return null;
		}
		if ($document === null) {
			// i-REST writes one document per linked third party: none means not linked (or not written yet)
			$this->error = $customer && ImRstDocument::lastWrite($db) !== null ? 'not_linked' : 'not_pushed';
			return null;
		}
		$answer = json_decode($document['content'], true);
		if (!is_array($answer)) {
			$this->error = 'invalid_response';
			return null;
		}
		if ($customer && isset($query['contract'])) {
			$answer = self::keepContract($answer, (string) $query['contract']);
		}

		return $answer;
	}

	/**
	 * Banks of hours of one contract and the tasks taken from them, as i-REST answers with the parameter "contract".
	 *
	 * @param  array<string,mixed> $answer     Banks of hours of a third party
	 * @param  string              $contractId Id of the contract
	 * @return array<string,mixed>
	 */
	private static function keepContract($answer, $contractId)
	{
		foreach ((array) ($answer['customers'] ?? array()) as $index => $customer) {
			$banks = array_values(array_filter((array) ($customer['banks'] ?? array()), function ($bank) use ($contractId) {
				return (string) ($bank['contract']['id'] ?? '') === $contractId;
			}));
			$keys = array_column($banks, 'key');
			$answer['customers'][$index]['banks'] = $banks;
			$answer['customers'][$index]['tickets'] = array_values(array_filter((array) ($customer['tickets'] ?? array()), function ($ticket) use ($keys) {
				return in_array($ticket['bank'] ?? null, $keys, true);
			}));
		}

		return $answer;
	}

	/**
	 * Message of the last failure, in the language of the user.
	 *
	 * @param  Translate $langs Translations
	 * @return string
	 */
	public function errorMessage($langs)
	{
		$keys = array(
			'not_configured' => 'IM_RST_ErrorNotConfigured',
			'not_pushed' => 'IM_RST_ErrorNotPushed',
			'report_not_written' => 'IM_RST_ErrorReportNotWritten',
			'unauthorized' => 'IM_RST_ErrorUnauthorized',
			'unsupported_version' => 'IM_RST_ErrorUnsupportedVersion',
			'not_linked' => 'IM_RST_ErrorNotLinked',
			'unavailable' => 'IM_RST_ErrorUnavailable',
		);

		return $langs->trans($keys[$this->error] ?? 'IM_RST_ErrorInvalidResponse');
	}
}
