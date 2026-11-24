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
 * \file    class/imrstinstaller.class.php
 * \ingroup irest
 * \brief   Base of the installation of i-REST in Dolibarr: group, API user, rights and key.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';

/**
 * Creates what i-REST needs before it can connect to this Dolibarr, from the manifest produced by i-REST
 * (install/manifest.json): a group holding the rights, an API user in that group and, on demand, its key.
 *
 * Only the elements created here are ever changed, and only to add what is missing: they are recognised by the ids
 * kept in constants, so an element of the same name made by someone else is reported and left alone.
 */
class ImRstInstaller
{
	/** Constant holding the id of the group created by the module. */
	const CONST_GROUP = 'IM_RST_INSTALL_GROUP_ID';

	/** Constant holding the id of the API user created by the module. */
	const CONST_USER = 'IM_RST_INSTALL_USER_ID';

	/** Constant set while the installation is limited to the read rights. */
	const CONST_READ_ONLY = 'IM_RST_INSTALL_READ_ONLY';

	/** Constant set when the module itself disabled the API user, so that only then it enables it again. */
	const CONST_DISABLED = 'IM_RST_INSTALL_USER_DISABLED';

	/** Format of the manifest this version reads. */
	const MANIFEST_SCHEMA = 1;

	/**
	 * @var DoliDB Database handler
	 */
	private $db;

	/**
	 * @var array<int,string> Errors of the last installation, for the administrator
	 */
	public $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Manifest delivered with the module.
	 *
	 * @return array<string,mixed>|null Manifest, null when it is missing or of another format
	 */
	public function manifest()
	{
		$path = dol_buildpath('/irest/install/manifest.json', 0);
		$content = is_readable($path) ? file_get_contents($path) : false;
		$manifest = $content !== false ? json_decode($content, true) : null;
		if (!is_array($manifest) || (int) ($manifest['schema'] ?? 0) !== self::MANIFEST_SCHEMA || ($manifest['application'] ?? '') !== 'dolibarr') {
			return null;
		}

		return $manifest;
	}

	/**
	 * Whether the module already created elements in this entity.
	 *
	 * @return bool
	 */
	public function isManaged()
	{
		return getDolGlobalInt(self::CONST_GROUP) > 0 || getDolGlobalInt(self::CONST_USER) > 0;
	}

	/**
	 * Whether the activation may create the base by itself: on a first installation, or to complete its own elements.
	 * A Dolibarr already configured for i-REST has its own API user, so no second one is created there.
	 *
	 * @return bool
	 */
	public function mayInstallAutomatically()
	{
		if ($this->isManaged()) {
			return true;
		}

		return getDolGlobalString('IM_RST_API_URL') === '' && getDolGlobalString('IM_RST_API_KEY') === '' && getDolGlobalString('IM_RST_MODE') !== 'push';
	}

	/**
	 * State of each element of the base.
	 *
	 * @param  Translate $langs Translations
	 * @return array<int,array{code:string,label:string,status:string}>|null Status: present, missing, foreign or
	 *                                                                       unavailable; null without manifest
	 */
	public function states($langs)
	{
		$manifest = $this->manifest();
		if ($manifest === null) {
			return null;
		}
		$groupId = getDolGlobalInt(self::CONST_GROUP);
		$userId = getDolGlobalInt(self::CONST_USER);
		$states = array(array('code' => 'module.api', 'label' => $langs->trans('IM_RST_InstallApiModule'), 'status' => isModEnabled('api') ? 'present' : 'missing'));
		$states[] = array('code' => 'group', 'label' => $langs->trans('IM_RST_InstallGroup', $manifest['names']['group']), 'status' => $this->elementState($groupId, $this->groupNamed($manifest['names']['group'])));
		$states[] = array('code' => 'user', 'label' => $langs->trans('IM_RST_InstallUser', $manifest['names']['user']), 'status' => $this->elementState($userId, $this->userNamed($manifest['names']['user'])));
		$states[] = array('code' => 'membership', 'label' => $langs->trans('IM_RST_InstallMembership'), 'status' => $groupId > 0 && $userId > 0 && $this->isMember($userId, $groupId) ? 'present' : 'missing');
		foreach ($this->expectedRights($manifest) as $right) {
			$rightId = $this->rightId($right['key']);
			$status = 'unavailable';
			if ($rightId > 0) {
				$status = $groupId > 0 && $this->groupHasRight($groupId, $rightId) ? 'present' : 'missing';
			}
			$states[] = array('code' => 'right.'.$right['key'], 'label' => $langs->trans('IM_RST_InstallRight', $right['key']), 'status' => $status);
		}
		$states[] = array('code' => 'key', 'label' => $langs->trans('IM_RST_InstallKey'), 'status' => $userId > 0 && $this->hasApiKey($userId) ? 'present' : 'missing');

		return $states;
	}

	/**
	 * Creates or completes the group, the API user, its membership and the rights. Never removes a right.
	 *
	 * @param  bool $readOnly Whether to give the read rights only (trial without writing)
	 * @return bool           Whether everything could be done (details in $errors)
	 */
	public function install($readOnly = false)
	{
		global $conf;

		$this->errors = array();
		$manifest = $this->manifest();
		if ($manifest === null) {
			$this->errors[] = 'IM_RST_ManifestMissing';

			return false;
		}
		$actor = $this->actor();
		if ($actor === null) {
			$this->errors[] = 'IM_RST_NoAdministrator';

			return false;
		}
		if ($readOnly) {
			dolibarr_set_const($this->db, self::CONST_READ_ONLY, '1', 'chaine', 0, '', $conf->entity);
		} else {
			dolibarr_del_const($this->db, self::CONST_READ_ONLY, $conf->entity);
		}

		$groupId = $this->ensureGroup((string) $manifest['names']['group']);
		$userId = $this->ensureUser((string) $manifest['names']['user'], $actor);
		if ($groupId <= 0 || $userId <= 0) {
			return false;
		}
		if (!$this->isMember($userId, $groupId)) {
			$member = new User($this->db);
			if ($member->fetch($userId) <= 0 || $member->SetInGroup($groupId, $conf->entity, 1) < 0) {
				$this->errors[] = 'IM_RST_InstallMembershipFailed';
			}
		}
		$group = new UserGroup($this->db);
		$group->fetch($groupId);
		foreach ($this->expectedRights($manifest) as $right) {
			$rightId = $this->rightId($right['key']);
			// A right of a module that is off does not exist yet: the page shows it as unavailable
			if ($rightId > 0 && !$this->groupHasRight($groupId, $rightId) && $group->addrights($rightId) < 0) {
				$this->errors[] = $right['key'];
			}
		}
		dol_syslog('ImRstInstaller::install group='.$groupId.' user='.$userId.' readOnly='.($readOnly ? 1 : 0), LOG_INFO);

		return empty($this->errors);
	}

	/**
	 * Generates a new key for the API user; the previous one stops working.
	 *
	 * @return string|null Key, to show once; null without API user or on error
	 */
	public function generateKey()
	{
		$userId = getDolGlobalInt(self::CONST_USER);
		if ($userId <= 0) {
			return null;
		}
		$key = getRandomPassword(true);
		// Written alone, as User::update() does (encrypted), so that nothing else of the user is saved again
		$sql = 'UPDATE '.$this->db->prefix()."user SET api_key = '".$this->db->escape(dolEncrypt($key, '', '', 'dolibarr'))."' WHERE rowid = ".((int) $userId);
		if (!$this->db->query($sql)) {
			dol_syslog('ImRstInstaller::generateKey '.$this->db->lasterror(), LOG_ERR);

			return null;
		}
		dol_syslog('ImRstInstaller::generateKey user='.$userId, LOG_INFO);

		return $key;
	}

	/**
	 * Disables the API user created by the module, when the module is turned off. Nothing is deleted.
	 *
	 * @return void
	 */
	public function disableUser()
	{
		global $conf;

		$userId = getDolGlobalInt(self::CONST_USER);
		if ($userId <= 0) {
			return;
		}
		$apiUser = new User($this->db);
		if ($apiUser->fetch($userId) > 0 && (int) $apiUser->status === User::STATUS_ENABLED && $apiUser->setstatus(User::STATUS_DISABLED) >= 0) {
			dolibarr_set_const($this->db, self::CONST_DISABLED, '1', 'chaine', 0, '', $conf->entity);
		}
	}

	/**
	 * Enables the API user again when the module had disabled it; one disabled by an administrator stays so.
	 *
	 * @return void
	 */
	public function enableUser()
	{
		global $conf;

		$userId = getDolGlobalInt(self::CONST_USER);
		if ($userId <= 0 || !getDolGlobalInt(self::CONST_DISABLED)) {
			return;
		}
		$apiUser = new User($this->db);
		if ($apiUser->fetch($userId) > 0 && $apiUser->setstatus(User::STATUS_ENABLED) >= 0) {
			dolibarr_del_const($this->db, self::CONST_DISABLED, $conf->entity);
		}
	}

	/**
	 * State of an element from the id kept by the module and the id of the element of that name.
	 *
	 * @param  int $ownId   Id kept by the module, 0 if none
	 * @param  int $namedId Id of the element of that name, 0 if none
	 * @return string       present, foreign or missing
	 */
	private function elementState($ownId, $namedId)
	{
		if ($ownId > 0) {
			return 'present';
		}

		return $namedId > 0 ? 'foreign' : 'missing';
	}

	/**
	 * Rights to give: all of them, or the read rights only while the installation is limited to them.
	 *
	 * @param  array<string,mixed> $manifest Manifest
	 * @return array<int,array<string,mixed>>
	 */
	private function expectedRights($manifest)
	{
		if (!getDolGlobalInt(self::CONST_READ_ONLY)) {
			return $manifest['rights'];
		}

		return array_values(array_filter($manifest['rights'], static function ($right) {
			return empty($right['write']);
		}));
	}

	/**
	 * Id of the group of the module, created if needed.
	 *
	 * @param  string $name Name of the group
	 * @return int          Id, <= 0 on error or when a group of that name is not ours
	 */
	private function ensureGroup($name)
	{
		global $conf;

		$id = getDolGlobalInt(self::CONST_GROUP);
		if ($id > 0) {
			return $id;
		}
		if ($this->groupNamed($name) > 0) {
			$this->errors[] = 'IM_RST_GroupExists';

			return -1;
		}
		$group = new UserGroup($this->db);
		$group->name = $name;
		$group->nom = $name;
		$group->entity = $conf->entity;
		$id = (int) $group->create(1);
		if ($id <= 0) {
			$this->errors[] = 'IM_RST_GroupNotCreated';

			return -1;
		}
		dolibarr_set_const($this->db, self::CONST_GROUP, (string) $id, 'chaine', 0, '', $conf->entity);

		return $id;
	}

	/**
	 * Id of the API user of the module, created if needed, with a random password nobody sees.
	 *
	 * @param  string $login Login
	 * @param  User   $actor User making the change
	 * @return int           Id, <= 0 on error or when a user of that login is not ours
	 */
	private function ensureUser($login, $actor)
	{
		global $conf;

		$id = getDolGlobalInt(self::CONST_USER);
		if ($id > 0) {
			return $id;
		}
		if ($this->userNamed($login) > 0) {
			$this->errors[] = 'IM_RST_UserExists';

			return -1;
		}
		$apiUser = new User($this->db);
		$apiUser->login = $login;
		$apiUser->lastname = 'i-REST API';
		$apiUser->employee = 0;
		$apiUser->admin = 0;
		$apiUser->entity = $conf->entity;
		$id = (int) $apiUser->create($actor, 1);
		if ($id <= 0) {
			$this->errors[] = 'IM_RST_UserNotCreated';

			return -1;
		}
		$apiUser->setPassword($actor, getRandomPassword(true), 0, 1, 1);
		dolibarr_set_const($this->db, self::CONST_USER, (string) $id, 'chaine', 0, '', $conf->entity);

		return $id;
	}

	/**
	 * User making the changes: the one signed in, otherwise the first administrator (activation from the command line).
	 *
	 * @return User|null
	 */
	private function actor()
	{
		global $conf, $user;

		if (is_object($user) && !empty($user->id)) {
			return $user;
		}
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'user WHERE admin = 1 AND statut = 1 AND entity IN (0, '.((int) $conf->entity).') ORDER BY rowid';
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : null;
		if (!$row) {
			return null;
		}
		$admin = new User($this->db);
		if ($admin->fetch((int) $row->rowid) <= 0) {
			return null;
		}
		// UserGroup::create() and addrights() read the global user
		$user = $admin;

		return $admin;
	}

	/**
	 * Id of the group of that name in this entity, 0 if none.
	 *
	 * @param  string $name Name
	 * @return int
	 */
	private function groupNamed($name)
	{
		global $conf;

		return $this->firstId('SELECT rowid AS id FROM '.$this->db->prefix()."usergroup WHERE nom = '".$this->db->escape($name)."' AND entity IN (0, ".((int) $conf->entity).')');
	}

	/**
	 * Id of the user of that login in this entity, 0 if none.
	 *
	 * @param  string $login Login
	 * @return int
	 */
	private function userNamed($login)
	{
		global $conf;

		return $this->firstId('SELECT rowid AS id FROM '.$this->db->prefix()."user WHERE login = '".$this->db->escape($login)."' AND entity IN (0, ".((int) $conf->entity).')');
	}

	/**
	 * Whether the user belongs to the group in this entity.
	 *
	 * @param  int $userId  Id of the user
	 * @param  int $groupId Id of the group
	 * @return bool
	 */
	private function isMember($userId, $groupId)
	{
		global $conf;

		return $this->firstId('SELECT rowid AS id FROM '.$this->db->prefix().'usergroup_user WHERE fk_user = '.((int) $userId).' AND fk_usergroup = '.((int) $groupId).' AND entity = '.((int) $conf->entity)) > 0;
	}

	/**
	 * Whether the group holds the right in this entity.
	 *
	 * @param  int $groupId Id of the group
	 * @param  int $rightId Id of the right
	 * @return bool
	 */
	private function groupHasRight($groupId, $rightId)
	{
		global $conf;

		return $this->firstId('SELECT rowid AS id FROM '.$this->db->prefix().'usergroup_rights WHERE fk_usergroup = '.((int) $groupId).' AND fk_id = '.((int) $rightId).' AND entity = '.((int) $conf->entity)) > 0;
	}

	/**
	 * Whether the user has an API key.
	 *
	 * @param  int $userId Id of the user
	 * @return bool
	 */
	private function hasApiKey($userId)
	{
		return $this->firstId('SELECT rowid AS id FROM '.$this->db->prefix().'user WHERE rowid = '.((int) $userId)." AND api_key IS NOT NULL AND api_key <> ''") > 0;
	}

	/**
	 * Id of a right from its path ("societe.client.voir"), 0 while its module is off.
	 *
	 * @param  string $key Module, permission and optional sub-permission, separated by dots
	 * @return int
	 */
	private function rightId($key)
	{
		global $conf;

		$parts = explode('.', $key);
		$sql = 'SELECT id FROM '.$this->db->prefix().'rights_def WHERE entity = '.((int) $conf->entity);
		$sql .= " AND module = '".$this->db->escape($parts[0])."' AND perms = '".$this->db->escape($parts[1] ?? '')."'";
		$sql .= isset($parts[2]) ? " AND subperms = '".$this->db->escape($parts[2])."'" : " AND (subperms IS NULL OR subperms = '')";

		return $this->firstId($sql);
	}

	/**
	 * Column "id" of the first row of a query, 0 if none.
	 *
	 * @param  string $sql Query
	 * @return int
	 */
	private function firstId($sql)
	{
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : null;

		return $row ? (int) $row->id : 0;
	}
}
