# WHMCS-eFactura

Open-source WHMCS addon for the Romanian **RO e-Factura** system. It generates
UBL 2.1 / CIUS-RO invoices and sends them to ANAF.

**Română:** addon open-source pentru WHMCS care generează facturile în format
UBL 2.1 / CIUS-RO și le trimite în sistemul RO e-Factura al ANAF.

## Requirements

- WHMCS 9.0 with PHP 8.2 or newer and the curl, dom, openssl, zip and
  mbstring extensions.
- For invoices in another currency than RON: outbound HTTPS to
  `curs.bnr.ro`, where the cron downloads the BNR exchange rates (see
  [Invoice PDF and page](#invoice-pdf-and-page)).
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

## Installation

1. **Copy:** from the release archive, copy the `modules` folder into the
   WHMCS root folder; the addon lands in `modules/addons/efactura`.
2. **Activate:** Configuration > System Settings > Addon Modules >
   eFactura > Activate. The addon creates its database tables
   (`mod_efactura_*`) and its client custom fields.
3. **Access:** in the same place, Configure > Access Control: tick the admin
   role groups that may use the addon. The e-Factura panels of the invoice
   and credit note pages follow the same access.
4. **Cron:** the WHMCS system cron must run every 5 minutes (the command is
   in Configuration > System Settings > Automation Settings); the addon sends
   the documents after each run. See [Sending to ANAF](#sending-to-anaf) for
   an optional separate worker.
5. **WHMCS invoicing:** in Configuration > System Settings > Tax
   Configuration > VAT Settings:
   - turn on **Enable Proforma Invoicing**, **Sequential Paid Invoice
     Numbering** and **Set Invoice Date on Payment**: unpaid invoices stay
     proformas and get the fiscal number and date at payment;
   - **Sequential Invoice Number Format** is the fiscal series, with the
     `{NUMBER}` tag (for example `CRK-{NUMBER}`), and **Next Paid Invoice
     Number** must be higher than the last fiscal number issued;
   - optionally, **Custom Invoice Numbering** numbers the proformas in their
     own series, with another prefix (for example `CRP-{NUMBER}`).

   Also see [Requirements](#requirements) (Store Client Data Snapshot, time
   zone). The dashboard of the addon (Addons > eFactura) checks all
   of these; the processing cannot be turned on while one of them fails.
6. **Seller details:** Addons > eFactura > Settings: the company as
   registered at ANAF (name, CUI, VAT, address), the IBANs, the delay before
   sending and the clients whose invoices are issued before payment.
7. **ANAF OAuth application:** each WHMCS uses its own application,
   registered on the ANAF developer account of the company. The ANAF
   connection page of the addon lists the steps and shows the callback URL
   to register: `https://<WHMCS System URL>/modules/addons/efactura/oauth_callback.php`
   (ANAF accepts only https). An ANAF application cannot be edited later,
   so add from the start the callback URL of every WHMCS that will use it.
   Enter the Client ID and Client Secret on the same page; they stay in
   WHMCS, the secret encrypted.
8. **Connect to ANAF:** "Connect to ANAF" opens ANAF in the browser, where a
   person with SPV rights for the company chooses the qualified certificate
   (USB token); "Link for another person" sends that step to someone who
   does not use this WHMCS. "Test connection" checks the SPV rights for the
   company CUI. The authorization has to be renewed with the certificate
   once a year; the dashboard warns 30 days before.
9. **From test to production:** the addon starts on the ANAF test
   environment (`api.anaf.ro/test`), where documents have no legal value.
   Try it on a copy of WHMCS with fictive clients: on the live WHMCS, every
   number the addon issues is a real fiscal invoice. On the live WHMCS,
   choose **ANAF production** in Settings before turning on the processing.
   The same OAuth application and authorization are used for both
   environments. Documents already sent to the test environment are not
   sent to production automatically, and the SPV inbox starts over with the
   last 59 days of production messages.
10. **Turn on the processing** in Settings once the dashboard shows no
    blocking problem. If WHMCS has fiscal invoices issued before the addon
    by another system, register them first (see
    [Invoices issued before the addon](#invoices-issued-before-the-addon)).

**Română:** copiați folderul `modules` din arhivă în folderul principal
WHMCS, activați addonul (Configuration > System Settings > Addon Modules),
dați acces grupurilor de admini (Access Control) și verificați că rulează
cron-ul WHMCS la 5 minute. În Tax Configuration > VAT Settings porniți
Enable Proforma Invoicing, Sequential Paid Invoice Numbering și Set Invoice
Date on Payment; seria fiscală e Sequential Invoice Number Format (cu
`{NUMBER}`, de exemplu `CRK-{NUMBER}`), iar proformele pot avea o serie
proprie cu alt prefix (Custom Invoice Numbering, de exemplu `CRP-{NUMBER}`).
Completați datele firmei în setări, înregistrați aplicația OAuth pe contul
de dezvoltator ANAF al firmei (cu URL-ul de callback din pagina „Conectare
ANAF”, doar https) și conectați-vă cu certificatul calificat al unei
persoane cu drepturi SPV. Încercați totul pe o copie WHMCS, cu mediul de
test ANAF și clienți fictivi; pe WHMCS-ul real alegeți „ANAF producție”
înainte să porniți procesarea, fiindcă fiecare număr emis acolo e o factură
fiscală reală.

## Invoices issued before the addon

When another system (a billing addon, an invoicing service) issued fiscal
invoices for WHMCS invoices before the addon, register them before turning
on the processing. A registered invoice is never sent to ANAF, and:

- an unpaid one keeps its number and date when it is paid; otherwise WHMCS
  would give it a new number from the fiscal series, a second invoice for
  the same sale;
- its PDF and page get the title of an invoice, not of a proforma;
- a later cancellation or refund issues a storno that points to it and is
  sent to ANAF; a storno issued before counts, so nothing is reversed twice.

The rows come from a CSV file with a header row. `invoice_id` is the WHMCS
invoice (for a storno, the invoice it reverses), `number` and `issue_date`
as issued. A storno also gives its amounts: `storno_invoice_id`, when the
other system created it as a WHMCS invoice of its own with negative amounts
(that invoice then gets the title of an invoice too), or `net` and `tax`.

```
kind,invoice_id,number,issue_date,storno_invoice_id,net,tax
invoice,277,CRK-0094,2026-10-10,,,
storno,141,CRK-0096,2026-10-13,290,,
```

```
php modules/addons/efactura/cron/import-external.php invoices.csv
php modules/addons/efactura/cron/import-external.php invoices.csv --apply
```

The first command only checks the file and lists what it would register;
the second registers all the rows, or none when one has an error. Run it
with the WHMCS cron paused, so that no invoice is paid meanwhile. The
documents list shows them as "Reported before the addon"; for an invoice
in another currency than RON, the BNR rate of its date is saved with it.

**Română:** facturile fiscale emise înainte de addon de alt sistem se
înregistrează dintr-un fișier CSV, cu comanda de mai sus (fără `--apply` doar
verifică). O factură înregistrată nu se trimite niciodată la ANAF; la plată
își păstrează numărul și data, primește titlul de factură, iar o anulare sau
o rambursare ulterioară emite o stornare care o referă. Stornările emise
înainte se înregistrează și ele, ca nimic să nu fie stornat de două ori.

## Client data

With **Extend the client forms** on (the default), the addon adds its own
script and stylesheet to the client forms of the client area (registration,
checkout, profile, contacts) and to the client pages of the admin area. The
theme files are not changed; the forms were tested with the Twenty-One and
Nexus themes and with Lagom 2.

- **County and sector:** for Romania, State/Region becomes the list of the
  counties. For an address in Bucharest, a sector list appears and the
  sector is saved in the City field ("Sector 2").
- **Billing details** (registration, checkout, profile): the client chooses
  Individual or Legal entity.
  - A legal entity gives the company name and the CUI (its check digit is
    verified).
  - Optionally, it also gives the trade register number, in the old format
    (J40/1234/2020) or the new one (J2024012345008).
  - It ticks whether it is VAT registered. Only then the WHMCS Tax ID field
    holds the VAT number, RO followed by the CUI.
  - An individual may give the CNP (verified); without it, the e-Factura
    XML carries 13 zeros.
- **Fetch from ANAF**, next to the CUI: fills in the company name, the
  trade register number, the address and the VAT number from the ANAF VAT
  payer service (PlatitorTvaRest v9). It also shows whether the company is
  VAT registered, applies VAT on collection, is fiscally inactive or
  deregistered, and is in the RO e-Factura registry. A field already filled
  in with another value is replaced only if the client ticks it.
- **Validation on the server:** the same rules are checked when the form is
  saved, either strictly (invalid data cannot be saved) or as warnings only.
  This is set separately for new clients (registration, checkout) and for
  the profile and contacts. A Romanian company without CUI is always
  refused, and so is a CNP together with a company name: a client with a
  CNP is an individual. The admin client pages refuse only the latter; the
  API is not validated.
- **Read-only billing details in the profile** (on by default): the client
  sees the customer type, company name, CUI, trade register number, CNP and
  VAT number without being able to change them, with a link to open a
  ticket. A company can still update its address from ANAF.
- **Admin area:** the client profile, new client and contacts pages have the
  same county list, and the profile and new client pages also have the ANAF
  button next to the CUI. The client summary shows the CUI and the trade
  register number of a company, or the CNP of an individual who gave one.

The addon creates its own client custom fields: CUI, trade register number
and CNP, with Romanian translations. A deleted field is created again, and
the "Client fields" section of the settings shows their state. The county
is always the WHMCS State/Region field, and the VAT number the WHMCS Tax ID
field. Two settings (on by default) decide whether the CUI and the trade
register number appear on the invoice PDF ("Show on Invoice"). The CNP never
appears there. For a VAT payer whose Tax ID is RO + the same CUI, the PDF
shows the code once, as the VAT number: with Store Client Data Snapshot on,
the addon leaves the CUI out of the client details WHMCS keeps for the
invoice, from the proforma on.

The addon also creates admin-only client fields for the default invoice
references of a client: project (BT-11), contract (BT-12), purchase order
(BT-13), receiving advice (BT-15), buyer accounting reference (BT-19) and
buyer identifier (BT-46). An admin fills them in the client profile. They
are not shown in the client area and not printed on the invoice.

The ANAF lookup runs on the server and needs the WHMCS session token. It is
limited per IP address (20 lookups per 10 minutes), per session (10) and
per admin (120). The answers are kept for one day, or for one hour when ANAF
does not know the CUI, and ANAF is called at most once per second.

Behind Cloudflare or another reverse proxy, set **Proxy IP Header** and
**Trusted Proxies** (Configuration > System Settings > General Settings >
Security), so that WHMCS sees the visitor's IP address. Otherwise all
visitors share the address of the proxy, and with it one lookup limit.

**Română:** cu „Extinde formularele clienților” pornit (implicit), județul
se alege dintr-o listă, iar pentru București și sectorul (salvat în câmpul
oraș). La înregistrare, comandă și în profil clientul alege persoană fizică
sau juridică:
- firma completează denumirea și CUI-ul (cu cifra de control), opțional
  Nr. Reg. Com. și dacă e plătitoare de TVA (doar atunci Tax ID = RO + CUI);
- persoana fizică poate completa CNP-ul.

Butonul „Preia datele de la ANAF” completează datele firmei pe server, cu
limite pe IP, pe sesiune și pe admin, cache și cel mult o cerere pe secundă.

Serverul verifică aceleași reguli, strict sau doar cu avertizare. O firmă
din România fără CUI nu este acceptată niciodată, nici un CNP împreună cu o
denumire de firmă (refuzat și în admin). În profil, datele de facturare
sunt doar pentru citire (implicit).

Addonul își creează propriile câmpuri CUI, Nr. Reg. Com. și CNP și le
recreează dacă sunt șterse. Două setări decid dacă CUI-ul și Nr. Reg. Com.
apar pe factură; CNP-ul nu apare niciodată. La plătitorii de TVA cu Tax ID
= RO + același CUI, PDF-ul arată codul o singură dată, ca „Cod TVA”, de la
proformă încolo (cu Store Client Data Snapshot pornit). Tot el creează, doar pentru
admin, câmpurile cu referințele implicite pe factură ale clientului
(BT-11, BT-12, BT-13, BT-15, BT-19, BT-46). În spatele Cloudflare sau al
altui proxy, setați în WHMCS Proxy IP Header și Trusted Proxies, ca limita
pe IP să vadă adresa reală a vizitatorului.

## Sending to ANAF

The documents are sent by the WHMCS system cron: the addon runs its queue
after every cron run (`AfterCronJob`, at most 45 seconds per run). It uploads
the documents that are due, checks their status, downloads and archives the
signed answer of ANAF, resolves uploads whose answer was lost and emails the
administrators when a document gets close to the legal deadline (5 working
days from issue).

An upload whose answer was lost is looked for among the invoices sent for
the company CUI before it is sent again. It is sent again only after the
whole list of those invoices was read and each one not known to the addon
was downloaded and compared; until then it waits, so that ANAF never gets
the same invoice twice because of a list read in part. The e-Factura panel
of the invoice then says why it waits. A ZIP that cannot be read is not one
of the addon's (ANAF returns the bytes sent) and is downloaded once; a
download refused for the daily limit of ANAF is tried again the next day.

Invoices to clients outside Romania follow Settings > Invoices not reported.
By default, EU companies invoiced without VAT (reverse charge) and clients
outside the EU are not sent. An EU consumer charged VAT is sent with the
Romanian VAT: without the Union OSS scheme the place of supply is Romania.
An invoice with the VAT rate of the country of the client stops with an
explanation, and the dashboard warns about WHMCS tax rules that charge such
rates. A company registered in OSS turns on "EU consumers (OSS)": these
invoices are then declared through OSS and not sent.

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
alertele de termen). O încărcare fără răspuns se retrimite doar după ce
lista facturilor trimise a fost citită toată și fiecare factură necunoscută
a fost descărcată și comparată; până atunci, panoul e-Factura al facturii
arată de ce așteaptă. Facturile către persoane fizice din alte țări UE se
trimit cu TVA-ul românesc; una cu cota țării clientului se oprește, iar
panoul avertizează despre regulile de TVA din WHMCS care aplică astfel de
cote. Pentru o firmă înregistrată în OSS, setarea „Persoane fizice din UE
(OSS)” le exclude. Anularea sau rambursarea unei facturi fiscale emite o
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
- **Invoice references:** in the same panel, for buyers that ask for them
  (public institutions): project (BT-11), contract (BT-12), purchase order
  (BT-13), receiving advice (BT-15), buyer accounting reference (BT-19) and
  buyer identifier (BT-46). An empty field takes the default from the client
  profile (shown in grey), read when the XML is built. They can be changed
  until the invoice is sent to ANAF; the values sent then stay with the
  document, and a storno carries those of the invoice it corrects. A document
  rejected by ANAF can be corrected, references included, before it is sent
  again. CIUS-RO limits BT-12, BT-13 and BT-15 to 200 characters and BT-19 to
  100. They are not in the Options tab of the invoice because WHMCS locks that
  tab once the invoice is paid, which is when it becomes fiscal.
- **Credit note page:** the storno issued from the credit note.
- **Addon pages** (Addons > eFactura): the configuration checks and the
  state of the queue, the list of documents with filters, and the page of
  each document with its history.

The panels follow the addon access set in Configuration > Addon Modules.

**Română:** pe pagina facturii apare un panou e-Factura (stare, termen,
index ANAF, erori, fișiere, stornări) cu acțiunile: trimite acum, ține pe
loc, verifică datele, retrimite după corectare, emite fiscal acum,
stornare. Tot acolo se completează referințele facturii cerute de instituții
(proiect BT-11, contract BT-12, comandă BT-13, aviz de recepție BT-15,
referința contabilă a cumpărătorului BT-19, identificatorul cumpărătorului
BT-46): câmpul gol ia valoarea implicită din profilul clientului. Se pot
schimba până la trimiterea facturii la ANAF, apoi rămân cu documentul;
stornarea le preia de la factura pe care o corectează. Pagina notei de credit arată stornarea emisă din ea, iar paginile
addonului au lista documentelor și istoricul fiecăruia.

## Invoice PDF and page

The addon changes two things in the PDF of an invoice and on its page in the
client area. The theme is not changed:

- the invoice page: the `ClientAreaPageViewInvoice` hook (tested with
  Twenty-One and Lagom 2);
- the PDF: `InvoicePdfTemplateVars`, a hook point WHMCS 9.0 calls for every
  invoice PDF (download, e-mail attachment, admin area) but does not list in
  its hook reference.

**Title.** With proforma invoicing, WHMCS titles every invoice that is not
Paid as a proforma: also an invoice issued as a fiscal invoice before
payment, and a fiscal invoice cancelled or refunded later. The addon gives
such an invoice the title of a paid one ("Invoice #CRK-0096", the
`invoicenumber` text of the language). Proformas keep their title.

**BNR exchange rate.** A fiscal invoice with VAT in another currency than RON
gets the BNR exchange rate and the VAT in RON (art. 319 alin. (23) of the
Fiscal Code) in its notes, with the text of its e-Factura XML, in the
language of the client:

```
Curs BNR 1 EUR = 5,0801 RON din 12.10.2026; TVA: 106,68 RON
BNR exchange rate of 12.10.2026: 1 EUR = 5.0801 RON; VAT: 106.68 RON
```

The rate is the last one BNR published before the invoice date, the same one
the XML uses (once the XML is built, the one saved with it). While the
processing is on, the WHMCS cron downloads the rates from `curs.bnr.ro`
every day after BNR publishes them (around 13:00); a payment never waits for
BNR. When the rate of an invoice is not downloaded yet (right after the
installation, or with a cron that stopped), its PDF goes out without it: the
WHMCS activity log and the dashboard check "BNR exchange rates" list the
invoice, so that it can be sent again once the rates are in. Proformas,
invoices in RON and invoices without VAT (reverse charge, clients outside the
EU) get nothing; the XML of an invoice without VAT still gives the VAT in RON
(0.00), as e-Factura requires for every document in another currency than
RON. WHMCS 9.0 has no PDF for credit notes (their Print and Download buttons
are "Coming Soon"), so a storno in another currency shows the rate only in
its XML.

Since the PDF hook point is not documented, the dashboard check "Invoice
PDF: title and BNR exchange rate" confirms it on each WHMCS version: after an
update, open the PDF of any invoice. If the warning stays, WHMCS no longer
calls it; add this line at the beginning of `invoicepdf.tpl` in the active
theme (`templates/<theme>/invoicepdf.tpl`), right after `<?php`:

```php
if (function_exists('efactura_invoice_title')) { $pagetitle = efactura_invoice_title($invoiceid, $pagetitle); $notes = efactura_invoice_notes($invoiceid, $notes); }
```

The line does nothing when the addon is missing or deactivated. A theme
update may replace the file, so check it again after one. The line of
version 1.0.0 set only the title: replace it with this one.

**Română:** addonul modifică PDF-ul și pagina facturii din zona clientului
fără să schimbe tema. WHMCS dă titlul de proformă oricărei facturi
neplătite, inclusiv celei emise fiscal înainte de plată și unei facturi
fiscale anulate sau rambursate; addonul îi dă titlul unei facturi plătite
(„Factura nr. CRK-0096”), iar proformele rămân proforme. O factură fiscală
cu TVA în altă monedă decât RON primește în note cursul BNR și TVA-ul în lei
(art. 319 alin. (23) Cod fiscal), cu textul din XML-ul ei, în limba
clientului. O factură fără TVA (taxare inversă, client din afara UE) nu
primește mențiunea, dar XML-ul ei are în continuare TVA-ul în lei (0,00).
Cursul este ultimul publicat de BNR înainte de data facturii, același ca în
XML. Cât timp procesarea e pornită, cron-ul WHMCS descarcă zilnic cursurile
de pe `curs.bnr.ro`, după ce BNR le publică (în jurul orei 13:00), deci
plata nu așteaptă după BNR. Dacă lipsește cursul unei facturi, PDF-ul ei
iese fără mențiune, iar jurnalul de activitate WHMCS și verificarea „Cursuri
BNR” din panou o arată, ca s-o puteți retrimite. WHMCS 9.0 nu are PDF pentru
notele de credit, deci o stornare în valută are cursul doar în XML. Pentru
PDF, addonul folosește un hook pe care WHMCS îl apelează, dar nu îl
documentează, așa că verificarea din panou îl confirmă la fiecare versiune
WHMCS (după o actualizare, deschideți PDF-ul oricărei facturi). Dacă
avertismentul rămâne, puneți linia de mai sus la începutul fișierului
`invoicepdf.tpl` al temei active; linia din versiunea 1.0.0 punea doar
titlul și trebuie înlocuită.

## SPV inbox

Every 30 minutes, after the queue, the cron reads the e-Factura messages of
the company CUI from ANAF (at most 30 seconds per run). It goes on from where
it stopped for that ANAF environment and CUI; the first time, after a switch
from the test environment to production or to another CUI, it reads the last
59 days, since ANAF keeps the messages 60 days. A long list is read in parts
over several runs, without skipping any message:

- **invoices received** from suppliers: downloaded, archived as the signed
  ZIP of ANAF and shown with the supplier, number, date, total and lines;
- **messages from buyers** about the invoices sent by the addon: downloaded,
  linked to the fiscal document they are about, shown in the e-Factura panel
  of the invoice, and e-mailed to the administrators;
- the answers to the addon's own uploads (sent, errors): listed and linked to
  their documents; the queue already archives their files.

The inbox page (Addons > eFactura > SPV inbox) has filters, the
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
răspunsurile la propriile încărcări. Citirea continuă de unde a rămas,
separat pentru fiecare mediu ANAF și CUI: prima dată, inclusiv după trecerea
de la mediul de test la producție, citește ultimele 59 de zile; o listă lungă
se citește în mai multe rulări, fără să sară mesaje. Pagina „Inbox SPV” are filtre,
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
