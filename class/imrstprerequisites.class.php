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
 * \file    class/imrstprerequisites.class.php
 * \ingroup irest
 * \brief   Preparation of Dolibarr for the workflows of i-REST.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
dol_include_once('/irest/class/imrstclient.class.php');

/**
 * Checks the prerequisites declared by i-REST for its workflows (extra fields, categories) and creates those
 * missing, with the rights of the administrator signed in. This class only knows how to check and create a kind of
 * element; which elements, with which codes and labels, comes from i-REST. An element that exists is never changed.
 */
class ImRstPrerequisites
{
	/** Kind "extra field" declared by i-REST. */
	const KIND_EXTRAFIELD = 'extrafield';

	/** Kind "category" declared by i-REST. */
	const KIND_CATEGORY = 'category';

	/**
	 * @var DoliDB Database handler
	 */
	private $db;

	/**
	 * @var ImRstClient Client of the API of i-REST
	 */
	private $client;

	/**
	 * @var bool Whether the last check could not send its report to i-REST (reason in the $error of the client)
	 */
	public $reportFailed = false;

	/**
	 * @param DoliDB      $db     Database handler
	 * @param ImRstClient $client Client of the API of i-REST
	 */
	public function __construct($db, $client)
	{
		$this->db = $db;
		$this->client = $client;
	}

	/**
	 * Prerequisites declared by i-REST with their state in this Dolibarr, reported back to i-REST.
	 *
	 * @param  Translate $langs Translations
	 * @return array<int,array<string,mixed>>|null List of prerequisite, status, detail, label; null on failure of i-REST
	 */
	public function check($langs)
	{
		$prerequisites = $this->client->prerequisites();
		if ($prerequisites === null) {
			return null;
		}
		$states = array();
		$report = array();
		foreach ($prerequisites as $prerequisite) {
			$state = $this->stateOf($prerequisite, $langs);
			if ($state === null) {
				continue;
			}
			$states[] = array('prerequisite' => $prerequisite) + $state;
			$report[(string) $prerequisite['code']] = array('status' => $state['status'], 'detail' => $state['detail']);
		}
		$this->reportFailed = !$this->client->report($report);

		return $states;
	}

	/**
	 * Creates the missing prerequisites among those chosen, with the choices of the administrator.
	 *
	 * @param  User                              $user    User signed in
	 * @param  Translate                         $langs   Translations
	 * @param  array<int,string>                 $codes   Codes of the prerequisites to create
	 * @param  array<string,array<string,string>> $choices Choices of the administrator, by code
	 * @return array{created:array<int,string>,errors:array<int,string>}|null Labels created and errors; null on failure of i-REST
	 */
	public function create($user, $langs, $codes, $choices)
	{
		$prerequisites = $this->client->prerequisites();
		if ($prerequisites === null) {
			return null;
		}
		$created = array();
		$errors = array();
		foreach ($prerequisites as $prerequisite) {
			$code = (string) $prerequisite['code'];
			$state = $this->stateOf($prerequisite, $langs);
			if (!in_array($code, $codes, true) || $state === null || $state['status'] !== 'missing') {
				continue;
			}
			$result = $prerequisite['kind'] === self::KIND_EXTRAFIELD
				? $this->createExtraField($prerequisite['spec'], $langs)
				: $this->createCategory($user, $prerequisite['spec'], $choices[$code] ?? array());
			if ($result > 0) {
				$created[] = $state['label'];
			} else {
				$errors[] = $state['label'];
			}
		}

		return array('created' => $created, 'errors' => $errors);
	}

	/**
	 * State of a prerequisite in this Dolibarr, null for a kind this module does not handle.
	 *
	 * @param  array<string,mixed> $prerequisite Prerequisite declared by i-REST
	 * @param  Translate           $langs        Translations
	 * @return array{status:string,detail:string|null,label:string}|null
	 */
	private function stateOf($prerequisite, $langs)
	{
		$spec = $prerequisite['spec'];
		if ($prerequisite['kind'] === self::KIND_EXTRAFIELD) {
			$label = $langs->trans('IM_RST_ExtraFieldOf', $spec['attrname'], $langs->transnoentitiesnoconv('IM_RST_Element_'.$spec['elementtype']));
			$extrafields = new ExtraFields($this->db);
			$extrafields->fetch_name_optionals_label($spec['elementtype'], true);
			$type = $extrafields->attributes[$spec['elementtype']]['type'][$spec['attrname']] ?? null;
			if ($type === null) {
				return array('status' => 'missing', 'detail' => null, 'label' => $label);
			}
			if ($type !== $spec['type']) {
				return array('status' => 'different', 'detail' => $langs->transnoentitiesnoconv('IM_RST_TypeIs', $type, $spec['type']), 'label' => $label);
			}
			if ($spec['type'] === 'select') {
				$options = $extrafields->attributes[$spec['elementtype']]['param'][$spec['attrname']]['options'] ?? array();
				$missing = array_diff(array_column($spec['values'], 'key'), array_map('strval', array_keys($options)));
				if (!empty($missing)) {
					return array('status' => 'different', 'detail' => $langs->transnoentitiesnoconv('IM_RST_ValuesMissing', implode(', ', $missing)), 'label' => $label);
				}
			}

			return array('status' => 'present', 'detail' => null, 'label' => $label);
		}
		if ($prerequisite['kind'] === self::KIND_CATEGORY) {
			$label = $langs->trans('IM_RST_CategoryNamed', $spec['label']);
			// i-REST looks for the category among the customer and supplier categories
			foreach (array(Categorie::TYPE_CUSTOMER, Categorie::TYPE_SUPPLIER) as $type) {
				$category = new Categorie($this->db);
				if ($category->fetch(0, (string) $spec['label'], $type) > 0) {
					return array('status' => 'present', 'detail' => null, 'label' => $label);
				}
			}

			return array('status' => 'missing', 'detail' => null, 'label' => $label);
		}

		return null;
	}

	/**
	 * Creates an extra field as declared by i-REST.
	 *
	 * @param  array<string,mixed> $spec  elementtype, attrname, type, size, position, list, alwayseditable, label, values
	 * @param  Translate           $langs Translations
	 * @return int                        > 0 if created, <= 0 on error
	 */
	private function createExtraField($spec, $langs)
	{
		$param = '';
		if ($spec['type'] === 'select') {
			$options = array();
			foreach ($spec['values'] as $value) {
				$options[(string) $value['key']] = self::text($value['label'], $langs);
			}
			$param = array('options' => $options);
		}
		$extrafields = new ExtraFields($this->db);

		return $extrafields->addExtraField(
			(string) $spec['attrname'],
			self::text($spec['label'], $langs),
			(string) $spec['type'],
			(int) $spec['position'],
			(string) $spec['size'],
			(string) $spec['elementtype'],
			0,
			0,
			'',
			$param,
			empty($spec['alwayseditable']) ? 0 : 1,
			'',
			(string) $spec['list']
		);
	}

	/**
	 * Creates a category as declared by i-REST, of the type chosen by the administrator.
	 *
	 * @param  User                 $user    User signed in
	 * @param  array<string,mixed>  $spec    label, type (customer or supplier)
	 * @param  array<string,string> $choices "type": customer or supplier
	 * @return int                           > 0 if created, <= 0 on error
	 */
	private function createCategory($user, $spec, $choices)
	{
		$type = in_array($choices['type'] ?? '', array(Categorie::TYPE_CUSTOMER, Categorie::TYPE_SUPPLIER), true) ? $choices['type'] : (string) $spec['type'];
		$category = new Categorie($this->db);
		$category->label = (string) $spec['label'];
		$category->type = $type;

		return $category->create($user);
	}

	/**
	 * Text in the language of the user from texts by language code (fr, en, de), English otherwise.
	 *
	 * @param  array<string,string>|string $texts Texts by language code
	 * @param  Translate                   $langs Translations
	 * @return string
	 */
	public static function text($texts, $langs)
	{
		if (!is_array($texts)) {
			return (string) $texts;
		}
		$language = substr((string) $langs->defaultlang, 0, 2);

		return (string) ($texts[$language] ?? $texts['en'] ?? reset($texts));
	}
}
