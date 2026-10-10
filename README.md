# WHMCS-eFactura

Open-source WHMCS addon for the Romanian **RO e-Factura** system. It generates
UBL 2.1 / CIUS-RO invoices and sends them to ANAF.

> Work in progress. Not ready for production use yet.

**Română:** addon open-source pentru WHMCS care generează facturile în format
UBL 2.1 / CIUS-RO și le trimite în sistemul RO e-Factura al ANAF.

## Requirements

- WHMCS 9.0 with PHP 8.2 or newer and the curl, dom, openssl, zip and
  mbstring extensions.
- Recommended: **Store Client Data Snapshot** on (Configuration > System
  Settings > General Settings). WHMCS then keeps the client details of each
  invoice for its PDF; the addon refreshes that copy when the invoice
  becomes fiscal and when it is sent, so the PDF and the e-Factura XML show
  the same buyer, and the PDF no longer changes afterwards.
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

Cancelling or refunding a fiscal invoice issues a storno, a new invoice in
the same fiscal series with negative quantities that points to the original
(BT-25/26):

- cancellation: the whole invoice, or what is left after partial refunds;
- refund of the whole invoice at once: the invoice lines negated;
- partial refund: one line "Stornare parțială factura ..." with the net and
  VAT of the credit note WHMCS creates for the refund.

Cancelling a proforma (unpaid invoice) issues nothing, and WHMCS credit
notes that do not come from a refund (applied credit, remaining balance)
are ignored. A storno is sent to ANAF only after its invoice is validated.

A Mass Pay invoice is not a fiscal invoice; the invoices it paid are.
Refunding it in full at once reverses each of them in full, except an
invoice it paid only in part (the rest of an invoice paid in part before).
A partial refund is not split automatically: the administrators get an e-mail and
issue the storno of each invoice concerned by hand.

For a faster queue, the same work can run from a separate cron entry, for
example every minute:

```
php -q /path/to/whmcs/modules/addons/efactura/cron/worker.php
```

**Română:** documentele sunt trimise de cron-ul WHMCS: după fiecare rulare,
addonul procesează coada (trimitere, verificarea stării, descărcarea și
arhivarea răspunsului semnat, reconcilierea încărcărilor fără răspuns și
alertele de termen). Anularea sau rambursarea unei facturi fiscale emite o
stornare în aceeași serie (totală, a restului sau parțială, după nota de
credit WHMCS); anularea unei proforme nu emite nimic. Opțional,
`cron/worker.php` poate rula separat, de exemplu la fiecare minut.

## Admin area

- **Invoice page:** an e-Factura panel shows the fiscal document of the
  invoice (state, send time, legal deadline, ANAF upload index and answer,
  errors), its files (the XML sent, the signed ZIP of ANAF or its errors) and
  its stornos. From there an admin sends a document now, holds or releases
  it, checks it against the current client data, sends a rejected one again
  after correcting the data, issues a proforma as a fiscal invoice before
  payment, or issues a storno of the whole invoice or of a part.
- **Credit note page:** the storno issued from the credit note.
- **Addon pages** (Addons > WHMCS-eFactura): the configuration checks and the
  state of the queue, the list of documents with filters, and the page of
  each document with its history.

The panels follow the addon access set in Configuration > Addon Modules.

**Română:** pe pagina facturii apare un panou e-Factura (stare, termen,
index ANAF, erori, fișiere, stornări) cu acțiunile: trimite acum, ține pe
loc, verifică datele, retrimite după corectare, emite fiscal acum,
stornare. Pagina notei de credit arată stornarea emisă din ea, iar paginile
addonului au lista documentelor și istoricul fiecăruia.

## SPV inbox

Every 30 minutes, after the queue, the cron reads the e-Factura messages of
the company CUI from ANAF (at most 30 seconds per run; the first time, the
last 59 days, since ANAF keeps the messages 60 days):

- **invoices received** from suppliers: downloaded, archived as the signed
  ZIP of ANAF and shown with the supplier, number, date, total and lines;
- **messages from buyers** about the invoices sent by the addon: downloaded,
  linked to the fiscal document they are about, shown in the e-Factura panel
  of the invoice, and e-mailed to the administrators;
- the answers to the addon's own uploads (sent, errors): listed and linked to
  their documents; the queue already archives their files.

The inbox page (Addons > WHMCS-eFactura > SPV inbox) has filters, the
messages not opened yet (also counted in the menu and on the dashboard) and a
"processed" mark for the invoices booked. A file ANAF no longer keeps is
marked as lost with the answer of ANAF.

The general SPV messages (notices of the tax administration, not e-Factura)
are not read: ANAF serves them only to a client authenticated with the
qualified certificate, not to the OAuth token the addon uses.

**Română:** la fiecare 30 de minute, cron-ul citește din SPV mesajele
e-Factura ale CUI-ului firmei: facturile primite de la furnizori (descărcate,
arhivate, afișate cu furnizor, număr, total și linii), mesajele
cumpărătorilor despre facturile trimise (legate de documentul fiscal,
afișate pe pagina facturii și trimise pe e-mail administratorilor) și
răspunsurile la propriile încărcări. Pagina „Inbox SPV” are filtre,
mesajele nedeschise și marcajul „procesat”. Mesajele generale din SPV (în
afara e-Factura) nu sunt citite: ANAF le servește doar cu certificatul
calificat, nu prin OAuth.

## Known issues

- **ANAF answers `A aparut o eroare tehnica. Cod: 1814` to some uploads.**
  On the ANAF test environment, on 2026-10-09, about half of the uploads got
  this answer for a while, whatever their content. These uploads did not
  appear in the ANAF message lists, and the same file was accepted when sent
  again. The addon treats the answer as uncertain: after 20 minutes it looks
  for the file in the list of sent invoices, and if it is not there it sends
  exactly the same bytes again one hour after the first attempt. When the
  error repeats, the administrators get an e-mail.

**Română:** ANAF răspunde la unele încărcări cu `A aparut o eroare tehnica.
Cod: 1814` (pe mediul de test, intermitent, la 9.10.2026). Addonul caută
fișierul în lista facturilor trimise și, dacă nu e acolo, retrimite aceiași
octeți după o oră; dacă eroarea se repetă, administratorii primesc un e-mail.

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
php tests/anaf-live-queue.php --yes   # the queue against the ANAF test environment (a fictive individual, the seller as test buyer)
php tests/anaf-live-inbox.php --yes   # reads the SPV inbox of the ANAF test environment (--cleanup forgets it)
php tests/admin-demo.php --create     # fictive invoices for trying the admin pages (--cleanup removes them)
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
