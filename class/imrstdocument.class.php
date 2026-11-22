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
 * \file    class/imrstdocument.class.php
 * \ingroup irest
 * \brief   Documents of the mode "push" kept in Dolibarr (table llx_irest_document).
 */

/**
 * Documents of the mode "push" of the current entity: JSON written by i-REST through the API of the module
 * (class/api_irest.class.php) and read by the pages of the module instead of calling i-REST, and the report of the
 * prerequisites written by the module for i-REST. A key holds letters, digits and "-" only.
 */
class ImRstDocument
{
	/** Version and connection, written by i-REST. */
	const STATUS = 'status';

	/** Prerequisites of the workflows, written by i-REST. */
	const PREREQUISITES = 'prerequisites';

	/** Banks of hours of all the customers, written by i-REST. */
	const HOURS_BANKS_OVERVIEW = 'hours-banks-overview';

	/** Start of the key of the banks of hours of a third party, followed by its id. */
	const HOURS_BANKS_PREFIX = 'hours-banks-';

	/** State of the prerequisites, written by the module and read back by i-REST. */
	const PREREQUISITES_REPORT = 'prerequisites-report';

	/** Name and version of the module, given to i-REST (never stored: answered by the API from the module itself). */
	const COMPANION = 'companion';

	/**
	 * Whether a key has the form of a key of a document.
	 *
	 * @param  string $key Key
	 * @return bool
	 */
	public static function isValidKey($key)
	{
		return is_string($key) && preg_match('/^[a-z0-9-]{1,64}$/', $key) === 1;
	}

	/**
	 * Reads a document of the current entity.
	 *
	 * @param  DoliDB $db  Database handler
	 * @param  string $key Key of the document
	 * @return array{content:string,date:int}|null|int Content and date of the last write (GMT timestamp), null when
	 *                                                 absent, -1 on error
	 */
	public static function fetch($db, $key)
	{
		global $conf;

		if (!self::isValidKey($key)) {
			return null;
		}
		$sql = "SELECT content, tms FROM ".MAIN_DB_PREFIX."irest_document";
		$sql .= " WHERE doc_key = '".$db->escape($key)."' AND entity = ".((int) $conf->entity);
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj) {
			return null;
		}

		return array('content' => (string) $obj->content, 'date' => (int) $db->jdate($obj->tms));
	}

	/**
	 * Writes a document of the current entity, created or replaced.
	 *
	 * @param  DoliDB $db      Database handler
	 * @param  string $key     Key of the document
	 * @param  string $content JSON text
	 * @return int             1 if written, -1 on error, -2 if the key or the content is not valid
	 */
	public static function save($db, $key, $content)
	{
		global $conf;

		if (!self::isValidKey($key) || !is_string($content) || json_decode($content) === null) {
			return -2;
		}
		$existing = self::fetch($db, $key);
		if ($existing === -1) {
			return -1;
		}
		if ($existing === null) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."irest_document (entity, doc_key, content, date_creation)";
			$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape($key)."', '".$db->escape($content)."', '".$db->idate(dol_now())."')";
		} else {
			// tms is updated by the database
			$sql = "UPDATE ".MAIN_DB_PREFIX."irest_document SET content = '".$db->escape($content)."'";
			$sql .= " WHERE doc_key = '".$db->escape($key)."' AND entity = ".((int) $conf->entity);
		}
		if (!$db->query($sql)) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * Deletes a document of the current entity.
	 *
	 * @param  DoliDB $db  Database handler
	 * @param  string $key Key of the document
	 * @return int         1 if deleted, 0 if absent, -1 on error
	 */
	public static function delete($db, $key)
	{
		global $conf;

		if (!self::isValidKey($key)) {
			return 0;
		}
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."irest_document";
		$sql .= " WHERE doc_key = '".$db->escape($key)."' AND entity = ".((int) $conf->entity);
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}

		return $db->affected_rows($resql) > 0 ? 1 : 0;
	}

	/**
	 * Date of the last document written by i-REST in the current entity (the report of the module left out).
	 *
	 * @param  DoliDB $db Database handler
	 * @return int|null   GMT timestamp, null when i-REST has written nothing
	 */
	public static function lastWrite($db)
	{
		global $conf;

		$sql = "SELECT MAX(tms) as last_write FROM ".MAIN_DB_PREFIX."irest_document";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND doc_key <> '".$db->escape(self::PREREQUISITES_REPORT)."'";
		$resql = $db->query($sql);
		if (!$resql) {
			return null;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return ($obj && $obj->last_write) ? (int) $db->jdate($obj->last_write) : null;
	}
}
