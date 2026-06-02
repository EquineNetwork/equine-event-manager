# Generated reference artifacts (NOT canonical mockups)

These files are **generated plugin output** captured during development for
visual reference — they are **not** canonical mockups. The "code follows the
mockup" rule (see `CLAUDE.md`) does **not** apply to anything in this folder.
The canonical mockups live one level up in `.mockups/*.html`.

Kept in version control so we don't lose the reference snapshots.

## Contents

- `c12-receipt-preview.html` — the hosted (web) receipt view rendered from the
  live C12 template (`templates/receipt/receipt.php`) for order `#00020`.
- `c12-receipt-preview.pdf` — an early C12 PDF render (pre brand-font bundling;
  shows the DejaVu Sans fallback).
- `c12-receipt-final.pdf` — the final C12 PDF after the brand-font bundling +
  layout fixes (billing card, running page footer, 2-col details, 700px sheet
  width to avoid Dompdf right-edge clipping).

These were the visual-verify reference for C12 (Order Receipt PDF + hosted
page). The authoritative source of truth remains the live template at
`templates/receipt/receipt.php` and the canonical `order_receipt.html` mockup.
