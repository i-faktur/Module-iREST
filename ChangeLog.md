# Changelog

All notable changes of iRest for Dolibarr. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
