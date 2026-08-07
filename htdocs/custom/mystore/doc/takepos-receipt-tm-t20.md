# TakePOS receipt — Epson TM-T20 (80mm) layout

## What
Reformats the ticket produced by the TakePOS **"Print ticket"** button into a layout
sized for an Epson TM-T20 thermal roll printer (72mm printable, monospace).

## How it works
- No core file is modified. Dolibarr's `htdocs/takepos/receipt.php` already exposes a
  native hook: it calls `executeHooks('TakeposReceipt', ...)` on context `takeposfrontend`,
  and if a hook sets `$hookmanager->resPrint`, that HTML replaces the entire default receipt
  and the page returns immediately.
- The MyStore hook lives in `class/actions_mystore.class.php` →
  `ActionsMystore::TakeposReceipt()`. It builds the receipt from `$mysoc` (company),
  `$object` (the `Facture`: ref, date, lines, totals) and its own SQL query for the
  payments/change (no payment data is in scope at the hook point), then sets `resPrint`.
- Context `takeposfrontend` is declared in `core/modules/modMyStore.class.php`
  (`module_parts['hooks']`).

## Requirements / setup
- **Print method must be "Browser"**: TakePOS setup → Receipt → `TAKEPOS_PRINT_METHOD = browser`.
  The TM-T20 is installed as the OS/browser printer. (The server-side ESC/POS path uses a
  different hook — `sendToPrinterBefore/After` — and is not what this covers.)
- Set the browser/OS print defaults for the TM-T20 to **80mm roll, no margins** so the
  `@page { margin:0; size:72mm auto }` CSS prints edge to edge without a second blank page.
- **After adding the `takeposfrontend` context you must re-activate the module** (disable +
  enable MyStore) so Dolibarr refreshes the stored hook list (`MAIN_MODULE_MYSTORE_*` consts).

## Honored admin settings
Reuses the same TakePOS constants as the core receipt where relevant:
`TAKEPOS_RECEIPT_NAME`, `TAKEPOS_SHOW_CUSTOMER`, `TAKEPOS_HIDE_DATE_OF_PRINTING`,
`TAKEPOS_SHOW_HT_RECEIPT`, and the free-text `TAKEPOS_HEADER` / `TAKEPOS_FOOTER`
(+ per-terminal suffix), with the standard substitution processing.

## Limitations
- In LNE certified mode (`isALNERunningVersion()`), core `receipt.php` forces its own format
  and never calls the hook, so this layout is bypassed by design.
- Adjust the width by editing the `body { width:72mm }` / `@page { size:72mm auto }` rules in
  `TakeposReceipt()` if using 58mm paper (use ~48mm) or a different printable area.
