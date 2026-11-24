# Changelog

All notable changes of iRest for Dolibarr. Versions follow [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-11-24

### Added
- Automatic setup, on a Dolibarr not yet configured for i-REST: module API REST, group "i-REST API" with its rights
  and API user `irest`, from a manifest produced by i-REST (`install/manifest.json`). Only the elements created by
  the module are completed; an element of the same name is reported and left alone.
- Tab "Installation" of the setup: state of each element, creation (read and write, or read-only for a trial), API key
  generated on demand and shown once.
- Command `scripts/irest_install.php` for the packages (`--read-only`, `--generate-token`, `--irest-url`,
  `--irest-key`, `--format=json`).

### Changed
- The module now depends on the module API REST.
- The prerequisites of the workflows are created without a click once the setup is saved with a working key.
- Disabling the module disables its API user (nothing is deleted); enabling it again restores that user.

## [1.1.0] - 2026-11-23

First published version.

### Added
- Tab "Banks of hours" of the third parties and contracts, read from the API of i-REST (version 1).
- Right "See the banks of hours computed by i-REST".
- Menu Third parties > Banks of hours: banks of hours of all the customers (current ones only or all, sorted by
  customer or by hours left, few or no hours left highlighted), with the right "See the banks of hours of all the
  customers".
- Setup: address of i-REST, key of its API (encrypted, write-only), time-out, test of the connection.
- Tab "Preparation" of the setup: prerequisites declared by i-REST (extra fields, categories), checked and reported
  back, the missing ones created on demand.
- Mode "push" (version 1.1.0, needed by i-REST for this mode): i-REST writes its data in Dolibarr through the API of
  the module (`irest/documents/{key}`, table `llx_irest_document`) and reads back the report of the prerequisites, so
  that Dolibarr never calls i-REST. Mode chosen in the setup ("read" by default); right "Write the data sent by
  i-REST", for the API user of i-REST only. The tab, the menu and the preparation read the documents in this mode.
