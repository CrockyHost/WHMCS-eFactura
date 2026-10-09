# WHMCS-eFactura

Open-source WHMCS addon for the Romanian **RO e-Factura** system. It generates
UBL 2.1 / CIUS-RO invoices and sends them to ANAF.

> Work in progress. Not ready for production use yet.

**Română:** addon open-source pentru WHMCS care generează facturile în format
UBL 2.1 / CIUS-RO și le trimite în sistemul RO e-Factura al ANAF.

## Requirements

- WHMCS 9.0 with PHP 8.2 or newer and the curl, dom, openssl, zip and
  mbstring extensions.
- PHP time zone `Europe/Bucharest`. WHMCS dates invoices with the PHP time
  zone, and at payment that date becomes the fiscal invoice date; with UTC,
  a payment made shortly after midnight in Romania gets the previous day.
  1. Recommended: add this line at the end of `configuration.php` in the
     WHMCS root folder. It applies to the web pages and to the cron, which
     runs PHP from the command line:
     ```php
     date_default_timezone_set('Europe/Bucharest');
     ```
  2. Also, for consistency, change `date.timezone` in `php.ini` or
     `.user.ini` (in cPanel: MultiPHP INI Editor). On its own this setting
     covers only the web pages, not the cron:
     ```ini
     date.timezone = Europe/Bucharest
     ```

**Română:** setați fusul orar PHP la `Europe/Bucharest`. Recomandat: linia
`date_default_timezone_set('Europe/Bucharest');` la sfârșitul fișierului
`configuration.php` (acoperă și paginile web, și cron-ul). În plus,
`date.timezone = Europe/Bucharest` în `php.ini` / `.user.ini` (cPanel:
MultiPHP INI Editor), care singur acoperă doar paginile web.

## Sending to ANAF

The documents are sent by the WHMCS system cron: the addon runs its queue
after every cron run (`AfterCronJob`, at most 45 seconds per run). It uploads
the documents that are due, checks their status, downloads and archives the
signed answer of ANAF, resolves uploads whose answer was lost and emails the
administrators when a document gets close to the legal deadline (5 working
days from issue).

For a faster queue, the same work can run from a separate cron entry, for
example every minute:

```
php -q /path/to/whmcs/modules/addons/efactura/cron/worker.php
```

**Română:** documentele sunt trimise de cron-ul WHMCS: după fiecare rulare,
addonul procesează coada (trimitere, verificarea stării, descărcarea și
arhivarea răspunsului semnat, reconcilierea încărcărilor fără răspuns și
alertele de termen). Opțional, `cron/worker.php` poate rula separat, de
exemplu la fiecare minut.

## Development

The repository root is the root of a local WHMCS 9 installation; only the
addon (`modules/addons/efactura/`) and the project files are tracked.

Tests run from the command line, without extra dependencies:

```
php tests/run.php        # unit tests, no WHMCS needed
php tests/run.php all    # unit + integration tests against the local WHMCS
php tests/run.php integration Queue   # only the test files whose name contains "Queue"
```

Integration tests run inside a database transaction that is rolled back.
The queue is tested against a simulator of the ANAF API (`tests/AnafSimulator.php`).

The e-Factura XML is checked in the tests with the official validators,
which are development tools and not part of the addon: the OASIS UBL 2.1
XSD (through libxml) and the CEN EN 16931 + CIUS-RO 1.0.9 Schematron,
compiled to XSLT 2.0 and run with Saxon-HE 10.9 (Java 8 or newer). Put them
in `../crocky-efactura-env/tools` or point `EFACTURA_TOOLS` to them; the
tests that need them are skipped otherwise.

```
php tests/concurrency.php --yes   # two simultaneous payments (creates and deletes test data)
php tests/anaf-validate.php       # sends the fictive fixtures to the public ANAF validator
```

`tests/fixtures/ubl/` holds the reviewed XML of each scenario, with fictive
data only (`tests/UblScenarios.php`); regenerate them with
`EFACTURA_UPDATE_FIXTURES=1 php tests/run.php` and review the diff.

## License

Copyright (C) 2026 S.C. CROCKY S.R.L. (CrockyHost)

WHMCS-eFactura is free software, licensed under the GNU General Public License
version 3 ([`LICENSE`](LICENSE)), with an additional permission for WHMCS and
additional terms under section 7 ([`ADDITIONAL-TERMS.md`](ADDITIONAL-TERMS.md)).
In short:

- You can use, study, modify and share it, including commercially.
- Anyone who distributes it, modified or not, must provide the full source code
  under the same license. You cannot distribute it only in encrypted form
  (for example ionCube), and you cannot impose license keys or any other
  restrictions on top of the GPL.
- The "WHMCS-eFactura by CrockyHost" attribution notice in the admin interface
  must be kept, and modified versions must be marked as modified.

This summary is not legal advice. The license texts are what apply.

WHMCS is a trademark of its respective owner. This project is not affiliated
with or endorsed by WHMCS or ANAF.
