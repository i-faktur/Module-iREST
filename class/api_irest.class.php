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
 * \file    class/api_irest.class.php
 * \ingroup irest
 * \brief   API of iRest for Dolibarr for i-REST (mode "push"), found by api/index.php of Dolibarr.
 */

use Luracast\Restler\RestException;

dol_include_once('/irest/class/imrstdocument.class.php');
dol_include_once('/irest/class/imrstclient.class.php');

/**
 * Documents of the mode "push" (/api/index.php/irest/documents/{key}): i-REST writes there what the pages of the
 * module show and reads back the report of the prerequisites, so that Dolibarr never calls i-REST. Only for the
 * user of the API of i-REST, with the right "Write the data sent by i-REST".
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Irest extends DolibarrApi
{
	/**
	 * Gives the API the database handler.
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
	}

	/**
	 * Read a document of iRest for Dolibarr
	 *
	 * The document "companion" gives the name and version of the module.
	 *
	 * @param  string $key Key of the document (letters, digits and "-")
	 * @return array       key, content (JSON text) and date of the last write (GMT timestamp)
	 *
	 * @url GET documents/{key}
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Document not found
	 */
	public function getDocument($key)
	{
		$this->checkRight();
		if ($key === ImRstDocument::COMPANION) {
			return array('key' => $key, 'content' => json_encode(array('name' => 'irest-dolibarr', 'version' => ImRstClient::MODULE_VERSION)), 'date' => dol_now());
		}
		$document = ImRstDocument::fetch($this->db, $key);
		if ($document === -1) {
			throw new RestException(500, 'Error when reading the document');
		}
		if ($document === null) {
			throw new RestException(404, 'Document not found');
		}

		return array('key' => $key, 'content' => $document['content'], 'date' => $document['date']);
	}

	/**
	 * Write a document of iRest for Dolibarr
	 *
	 * Creates or replaces the document.
	 *
	 * @param  string $key     Key of the document (letters, digits and "-")
	 * @param  string $content JSON text {@from body}
	 * @return array           key of the document written
	 *
	 * @url PUT documents/{key}
	 *
	 * @throws RestException 400 Key or content not valid
	 * @throws RestException 403 Not allowed
	 */
	public function putDocument($key, $content)
	{
		$this->checkRight();
		if ($key === ImRstDocument::COMPANION) {
			throw new RestException(400, 'The document "companion" cannot be written');
		}
		$result = ImRstDocument::save($this->db, $key, $content);
		if ($result === -2) {
			throw new RestException(400, 'Key or content not valid');
		}
		if ($result < 0) {
			throw new RestException(500, 'Error when writing the document');
		}

		return array('key' => $key);
	}

	/**
	 * Delete a document of iRest for Dolibarr
	 *
	 * @param  string $key Key of the document
	 * @return array       key of the document deleted
	 *
	 * @url DELETE documents/{key}
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Document not found
	 */
	public function deleteDocument($key)
	{
		$this->checkRight();
		$result = ImRstDocument::delete($this->db, $key);
		if ($result < 0) {
			throw new RestException(500, 'Error when deleting the document');
		}
		if ($result === 0) {
			throw new RestException(404, 'Document not found');
		}

		return array('key' => $key);
	}

	/**
	 * Refuses a user without the right to write the documents.
	 *
	 * @return void
	 *
	 * @throws RestException 403 Not allowed
	 */
	private function checkRight()
	{
		if (!isModEnabled('irest') || !DolibarrApiAccess::$user->hasRight('irest', 'document', 'write')) {
			throw new RestException(403, 'Not allowed: right "Write the data sent by i-REST" of iRest for Dolibarr');
		}
	}
}
