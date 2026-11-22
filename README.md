# iRest for Dolibarr

Dolibarr module of I-Motion (number 407006, prefix `IM_RST`), companion of the **i-REST** application (billing of
GLPI tickets in Dolibarr, creation of the GLPI entities and users of the Dolibarr customers). It computes nothing: it
shows what i-REST computes and creates what i-REST declares, in one of two modes chosen in its setup:

- **Read** (by default): the module reads the API of i-REST, server to server, with a key issued by i-REST.
- **Push**: i-REST writes its data in Dolibarr through the API of the module (`irest/documents/{key}`, table
  `llx_irest_document`) and reads back the state of the prerequisites; Dolibarr never calls i-REST.

- **Tab "Banks of hours"** of the third parties and contracts: banks of hours of the customer (bought, carried over,
  used, left, end of period, scope) and the tasks taken from them, as computed by i-REST from the contracts. Shown to
  the users with the right *See the banks of hours computed by i-REST*.
- **Menu Third parties > Banks of hours**: one row by bank of hours of every customer, current banks only or all,
  sorted by customer or by hours left, few or no hours left highlighted; the name of a customer opens its tab. Shown
  to the internal users with the right *See the banks of hours of all the customers*.
- **Preparation** (setup of the module): what the workflows of i-REST need in this Dolibarr (extra fields of the
  third parties and contract lines, category of the third parties to sync), with its state; the missing elements are
  created with the rights of the administrator signed in, nothing that exists is changed. The state is sent back to
  i-REST, which refuses to turn on a workflow while a required element is missing.

## Requirements

- Dolibarr 23 or 24 (modules Third parties and Contracts), PHP 8.1 or later.
- Mode read: i-REST reachable from the Dolibarr server (server to server: the browsers of the users never call
  i-REST).
- Mode push: module *API REST* of Dolibarr enabled, and the mode push chosen in i-REST on its Dolibarr
  connection.

## Installation

1. Copy the module into `htdocs/custom/irest` (the directory name must stay `irest`).
2. *Home > Setup > Modules*: enable *iRest for Dolibarr*.
3. Mode read: in i-REST, screen of the Dolibarr connection, *Companion application*: issue a key; then setup of the
   module: address of i-REST, key, then *Test the connection to i-REST*.
   Mode push: in i-REST, same screen, choose the mode push; give the API user of i-REST the right *Write the data
   sent by i-REST*; setup of the module: mode push, then *Check the data written by i-REST*.
5. *Preparation* tab: create the missing elements.
6. Users who may see the banks of hours: right *See the banks of hours computed by i-REST* (tabs) and *See the
   banks of hours of all the customers* (menu).

The key is stored encrypted (`dolEncrypt()`, constant `IM_RST_API_KEY`); errors of the calls to i-REST go to the
Dolibarr log, never the key.

## License

GPL-3.0-or-later, see `COPYING`. Copyright (C) 2026 I-Motion SARL.
