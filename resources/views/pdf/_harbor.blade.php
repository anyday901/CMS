{{--
    Harbor colours for the PDFs (invoices, quotes and statements), from the
    brand and ink scales in resources/css/app.css. Text stays dark and every
    tint has a rule or border beside it, so a page printed in greyscale, or
    without background colours, reads the same.
--}}
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #13322f; }
    h1 { font-size: 22px; margin: 0; color: #0d5048; }
    a { color: #0c6457; }
    .muted { color: #5b736f; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 2px; }
    .status { display: inline-block; padding: 2px 8px; border-radius: 4px; border: 1px solid; font-weight: bold; text-transform: uppercase; font-size: 10px; }
    .status-paid { background: #d7f0ea; color: #0d5048; border-color: #0d5048; }
    .status-unpaid { background: #ffdf9e; color: #78350f; border-color: #78350f; }
    .status-other { background: #e7efed; color: #334c48; border-color: #334c48; }
    table { width: 100%; border-collapse: collapse; }
    .header { border-bottom: 3px solid #0e7c6b; }
    .header td { vertical-align: top; padding-bottom: 12px; }
    .items { margin-top: 24px; }
    .items th { text-align: left; background: #eef8f5; border-bottom: 2px solid #0e7c6b; padding: 6px 4px; color: #0d5048; }
    .items td { border-bottom: 1px solid #dbe7e4; padding: 6px 4px; }
    .num, .items th.num { text-align: right; white-space: nowrap; }
    .note { color: #46605c; font-size: 10px; }
    .totals td { padding: 4px; }
    .totals .label { text-align: right; color: #46605c; }
    .grand td { font-weight: bold; color: #0a1f1d; border-top: 2px solid #0c6457; }
