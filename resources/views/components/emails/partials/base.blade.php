<!DOCTYPE html>
<html lang="th" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="color-scheme" content="dark" />
  <meta name="supported-color-schemes" content="dark" />
  <title>{{ $subject ?? 'Luilaykhao' }}</title>
  <!--[if mso]>
  <noscript>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
  </noscript>
  <![endif]-->
  <style>
    /* Reset */
    *, *::before, *::after { box-sizing: border-box; }
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; display: block; }
    a { color: inherit; text-decoration: none; }

    /* Base. Thai faces lead the stack so Windows/Outlook does not substitute a
       face that renders Thai tone marks at the wrong height. */
    body {
      margin: 0; padding: 0;
      background-color: #0a0a0a;
      font-family: 'Sarabun', 'Leelawadee UI', 'Tahoma', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      color: #e8edf5;
      -webkit-font-smoothing: antialiased;
    }

    /* Outer wrapper */
    .email-outer {
      width: 100%;
      background-color: #0a0a0a;
      padding: 40px 16px 32px;
    }
    .email-wrapper {
      max-width: 600px;
      margin: 0 auto;
      background: #141414;
      border: 1px solid #2a2a2a;
      border-radius: 18px;
      overflow: hidden;
    }

    /* Header */
    .email-header {
      padding: 36px 40px 32px;
      text-align: center;
      background: #1a1a1a;
    }
    .email-brand {
      display: block;
      margin-bottom: 20px;
      font-size: 11px;
      font-weight: 700;
      color: #ffffff;
      opacity: 0.65;
      letter-spacing: 4px;
      text-transform: uppercase;
    }
    .header-emoji {
      font-size: 38px;
      line-height: 1;
      margin: 0 0 14px;
    }
    .header-title {
      margin: 0 0 8px;
      font-size: 23px;
      font-weight: 700;
      color: #ffffff;
      line-height: 1.3;
    }
    .header-subtitle {
      margin: 0 0 22px;
      font-size: 14px;
      color: #ffffff;
      opacity: 0.78;
      line-height: 1.55;
    }
    .ref-badge {
      display: inline-block;
      padding: 7px 18px;
      border: 1px solid rgba(255,255,255,0.28);
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      color: #ffffff;
      letter-spacing: 2.5px;
      text-transform: uppercase;
    }

    /* Header tints — deep, low-saturation so white type stays legible */
    .hdr-teal  { background: #0d2b2a; }
    .hdr-green { background: #10291d; }
    .hdr-red   { background: #2c1519; }
    .hdr-amber { background: #2b1e0e; }
    .hdr-blue  { background: #14213f; }
    .hdr-slate { background: #1a1a1a; }

    /* Body */
    .email-body { padding: 36px 40px 32px; }

    /* Greeting block */
    .greeting {
      font-size: 14px;
      line-height: 1.75;
      color: #a9b8cd;
      margin: 0 0 28px;
      padding: 16px 20px;
      background: #1c1c1c;
      border-left: 3px solid #2dd4bf;
      border-radius: 4px 12px 12px 4px;
    }
    .greeting strong { color: #e8edf5; font-weight: 700; }

    /* Plain paragraph inside the body */
    .body-text {
      font-size: 14px;
      line-height: 1.75;
      color: #a9b8cd;
      margin: 0 0 24px;
    }
    .body-text strong { color: #e8edf5; font-weight: 700; }

    /* Section label — underline via border, not a pseudo-element (Gmail strips those) */
    .section-label {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: #7183a0;
      margin: 0 0 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #2a2a2a;
    }

    /* Info card */
    .info-card {
      background: #171717;
      border: 1px solid #2a2a2a;
      border-radius: 14px;
      overflow: hidden;
      margin-bottom: 24px;
    }
    .info-card-header {
      padding: 11px 20px;
      background: #1c1c1c;
      border-bottom: 1px solid #2a2a2a;
    }
    .info-card-title {
      font-size: 10px;
      font-weight: 700;
      color: #8fa1ba;
      text-transform: uppercase;
      letter-spacing: 1.2px;
    }

    /* Info row — table layout, not flex: Outlook and Gmail drop flex entirely */
    .info-row {
      display: table;
      width: 100%;
      padding: 12px 20px;
      border-bottom: 1px solid #242424;
    }
    .info-row:last-child { border-bottom: none; }
    .info-label {
      display: table-cell;
      font-size: 12px;
      color: #7183a0;
      font-weight: 400;
      width: 40%;
      vertical-align: top;
      padding-top: 1px;
    }
    .info-value {
      display: table-cell;
      font-size: 13px;
      color: #e8edf5;
      font-weight: 600;
      text-align: right;
      vertical-align: top;
      line-height: 1.5;
    }
    .info-value.accent-teal  { color: #2dd4bf; }
    .info-value.accent-amber { color: #fbbf24; }
    .info-value.accent-red   { color: #f87171; }
    .info-value.accent-blue  { color: #60a5fa; }
    .info-value.accent-green { color: #4ade80; }
    .info-value.lg { font-size: 18px; font-weight: 700; }

    /* Inline text accents */
    .t-teal  { color: #2dd4bf; }
    .t-green { color: #4ade80; }
    .t-amber { color: #fbbf24; }
    .t-red   { color: #f87171; }
    .t-blue  { color: #60a5fa; }
    .t-muted { color: #7183a0; }

    /* Pickup block */
    .pickup-block {
      padding: 12px 20px;
      border-bottom: 1px solid #242424;
    }
    .pickup-block:last-child { border-bottom: none; }
    .pickup-label {
      font-size: 12px;
      color: #7183a0;
      font-weight: 400;
      margin-bottom: 4px;
    }
    .pickup-location {
      font-size: 13px;
      color: #e8edf5;
      font-weight: 600;
      line-height: 1.5;
    }
    .pickup-region {
      font-size: 12px;
      color: #fbbf24;
      font-weight: 400;
      margin-top: 3px;
    }

    /* Amount highlight box */
    .highlight-box {
      border-radius: 14px;
      padding: 20px 24px;
      margin: 0 0 24px;
      border: 1px solid;
      border-left-width: 3px;
    }
    .highlight-box .amount-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .highlight-box .amount {
      font-size: 30px;
      font-weight: 700;
      line-height: 1.15;
    }
    .highlight-box .amount-note {
      font-size: 12px;
      font-weight: 400;
      margin-top: 6px;
      line-height: 1.55;
    }

    /* Highlight variants */
    .hl-teal  { background: #0f2b29; border-color: #1f5f59; }
    .hl-teal .amount-label, .hl-teal .amount-note  { color: #5eead4; }
    .hl-teal .amount  { color: #2dd4bf; }

    .hl-green { background: #10291d; border-color: #226b40; }
    .hl-green .amount-label, .hl-green .amount-note { color: #86efac; }
    .hl-green .amount { color: #4ade80; }

    .hl-amber { background: #2b1e0e; border-color: #7c5312; }
    .hl-amber .amount-label, .hl-amber .amount-note { color: #fcd34d; }
    .hl-amber .amount { color: #fbbf24; }

    .hl-red   { background: #2c1519; border-color: #7d2a2f; }
    .hl-red .amount-label, .hl-red .amount-note { color: #fca5a5; }
    .hl-red .amount { color: #f87171; }

    .hl-blue  { background: #14213f; border-color: #2a4a86; }
    .hl-blue .amount-label, .hl-blue .amount-note { color: #93c5fd; }
    .hl-blue .amount { color: #60a5fa; }

    .hl-slate { background: #1c1c1c; border-color: #383838; }
    .hl-slate .amount-label, .hl-slate .amount-note { color: #8fa1ba; }
    .hl-slate .amount { color: #e8edf5; }

    /* Status box */
    .status-box {
      border-radius: 14px;
      padding: 22px 24px;
      margin: 0 0 24px;
      text-align: center;
      border: 1px solid;
      border-left-width: 3px;
    }
    .status-label-small {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .status-value {
      font-size: 19px;
      font-weight: 700;
    }
    .st-green { background: #10291d; border-color: #226b40; }
    .st-green .status-label-small { color: #86efac; }
    .st-green .status-value { color: #4ade80; }

    .st-red { background: #2c1519; border-color: #7d2a2f; }
    .st-red .status-label-small { color: #fca5a5; }
    .st-red .status-value { color: #f87171; }

    .st-amber { background: #2b1e0e; border-color: #7c5312; }
    .st-amber .status-label-small { color: #fcd34d; }
    .st-amber .status-value { color: #fbbf24; }

    .st-blue { background: #14213f; border-color: #2a4a86; }
    .st-blue .status-label-small { color: #93c5fd; }
    .st-blue .status-value { color: #60a5fa; }

    /* Data table */
    .table-wrap {
      border-radius: 14px;
      overflow: hidden;
      border: 1px solid #2a2a2a;
      margin: 0 0 24px;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table thead tr { background: #1c1c1c; }
    .data-table thead th {
      padding: 10px 14px;
      text-align: left;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: #7183a0;
      border-bottom: 1px solid #2a2a2a;
    }
    .data-table thead th:last-child { text-align: right; }
    .data-table tbody tr { border-bottom: 1px solid #242424; background: #171717; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody td {
      padding: 11px 14px;
      color: #c3d0e2;
      font-weight: 400;
      vertical-align: middle;
    }
    .data-table tbody td:last-child { text-align: right; }
    .cell-index { color: #64748b; font-size: 12px; }
    .cell-strong { font-weight: 600; color: #e8edf5; }
    .cell-muted { color: #8fa1ba; }
    .badge-paid, .badge-pending, .badge-overdue {
      display: inline-block; padding: 4px 11px; border-radius: 999px;
      font-size: 10px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase;
    }
    .badge-paid    { background: #10291d; color: #4ade80; }
    .badge-pending { background: #2b1e0e; color: #fbbf24; }
    .badge-overdue { background: #2c1519; color: #f87171; }

    /* Alert box */
    .alert-box {
      padding: 14px 18px;
      margin: 0 0 20px;
      border-radius: 4px 12px 12px 4px;
      border-left: 3px solid;
    }
    .alert-title {
      font-size: 13px;
      font-weight: 700;
      margin: 0 0 4px;
    }
    .alert-text {
      font-size: 13px;
      font-weight: 400;
      line-height: 1.7;
      margin: 0;
    }

    /* Alert variants */
    .alert-teal { background: #0f2b29; border-left-color: #2dd4bf; }
    .alert-teal .alert-title { color: #5eead4; }
    .alert-teal .alert-text  { color: #9ad9d0; }

    .alert-green { background: #10291d; border-left-color: #4ade80; }
    .alert-green .alert-title { color: #86efac; }
    .alert-green .alert-text  { color: #a7d8b6; }

    .alert-amber { background: #2b1e0e; border-left-color: #fbbf24; }
    .alert-amber .alert-title { color: #fcd34d; }
    .alert-amber .alert-text  { color: #e2c88f; }

    .alert-red { background: #2c1519; border-left-color: #f87171; }
    .alert-red .alert-title { color: #fca5a5; }
    .alert-red .alert-text  { color: #e0a8a8; }

    .alert-blue { background: #14213f; border-left-color: #60a5fa; }
    .alert-blue .alert-title { color: #93c5fd; }
    .alert-blue .alert-text  { color: #a8bfe0; }

    .alert-neutral { background: #1c1c1c; border-left-color: #64748b; }
    .alert-neutral .alert-title { color: #c3d0e2; }
    .alert-neutral .alert-text  { color: #8fa1ba; }

    /* CTA button */
    .cta-wrap { text-align: center; margin: 28px 0 18px; }
    .cta-btn {
      display: inline-block;
      padding: 14px 36px;
      border-radius: 999px;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
    }
    .cta-teal  { background: #14b8a6; color: #052e2b; }
    .cta-green { background: #22c55e; color: #052e16; }
    .cta-red   { background: #ef4444; color: #2c0b0b; }
    .cta-amber { background: #f59e0b; color: #2e1902; }
    .cta-blue  { background: #3b82f6; color: #08182f; }
    .cta-slate { background: #cbd5e1; color: #0f172a; }

    /* Steps — table layout so the number column survives Gmail/Outlook */
    .steps-wrap { margin: 0 0 24px; }
    .step-item {
      display: table;
      width: 100%;
      padding: 14px 0;
      border-bottom: 1px solid #242424;
    }
    .step-item:last-child { border-bottom: none; padding-bottom: 0; }
    .step-item:first-child { padding-top: 0; }
    .step-num {
      display: table-cell;
      width: 28px;
      border-radius: 999px;
      text-align: center;
      vertical-align: middle;
      font-size: 12px;
      font-weight: 700;
      line-height: 28px;
    }
    .step-teal  { background: #134e4a; color: #5eead4; }
    .step-green { background: #14532d; color: #86efac; }
    .step-content { display: table-cell; padding-left: 14px; vertical-align: top; }
    .step-title { font-size: 13px; font-weight: 700; color: #e8edf5; margin: 0 0 3px; }
    .step-desc { font-size: 12px; color: #8fa1ba; font-weight: 400; margin: 0; line-height: 1.6; }

    /* Contact bar */
    .contact-bar {
      padding: 13px 18px;
      background: #1c1c1c;
      border: 1px solid #2a2a2a;
      border-radius: 12px;
      margin: 20px 0 0;
      font-size: 13px;
      color: #8fa1ba;
      line-height: 1.6;
    }
    .contact-bar strong { color: #e8edf5; font-weight: 700; }

    /* Footer */
    .email-footer {
      background: #101010;
      border-top: 1px solid #2a2a2a;
      padding: 24px 40px;
      text-align: center;
    }
    .footer-logo {
      font-size: 11px;
      font-weight: 700;
      color: #c3d0e2;
      margin-bottom: 4px;
      letter-spacing: 3px;
      text-transform: uppercase;
    }
    .footer-tagline {
      font-size: 12px;
      color: #7183a0;
      margin-bottom: 16px;
      font-weight: 400;
    }
    .footer-divider { height: 1px; background: #2a2a2a; margin: 0 0 14px; }
    .footer-disclaimer { font-size: 11px; color: #64748b; line-height: 1.9; }
    .footer-disclaimer strong { color: #a9b8cd; }

    /* Responsive */
    @media only screen and (max-width: 640px) {
      .email-outer { padding: 20px 10px 24px; }
      .email-header { padding: 30px 22px 26px; }
      .email-body { padding: 26px 22px 24px; }
      .email-footer { padding: 20px; }
      .header-title { font-size: 20px; }
      .header-emoji { font-size: 32px; }
      .highlight-box .amount { font-size: 25px; }
      .info-row, .info-label, .info-value { display: block; width: auto; }
      .info-label { margin-bottom: 3px; }
      .info-value { text-align: left; }
    }
  </style>
</head>
<body>
<div class="email-outer">
  <div class="email-wrapper">
    {{ $slot }}
  </div>
  <div style="height:20px;"></div>
  <div style="text-align:center; font-size:11px; color:#64748b; padding-bottom:8px;">
    &copy; {{ date('Y') }} Luilaykhao &middot; ส่งจากระบบอัตโนมัติ
  </div>
</div>
</body>
</html>
