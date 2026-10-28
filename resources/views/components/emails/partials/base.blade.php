<!DOCTYPE html>
<html lang="th" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="color-scheme" content="light" />
  <meta name="supported-color-schemes" content="light" />
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
    a { color: #047857; text-decoration: none; }

    /* Base. Thai faces lead the stack so Windows/Outlook does not substitute a
       face that renders Thai tone marks at the wrong height. */
    body {
      margin: 0; padding: 0;
      background-color: #f3f4f6;
      font-family: 'Sarabun', 'Leelawadee UI', 'Tahoma', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      color: #1f2937;
      -webkit-font-smoothing: antialiased;
    }

    /* Outer wrapper */
    .email-outer {
      width: 100%;
      background-color: #f3f4f6;
      padding: 32px 16px;
    }
    .email-wrapper {
      max-width: 600px;
      margin: 0 auto;
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      overflow: hidden;
    }

    /* Header — white letterhead; the hdr-* class only sets the colour of the
       top rule, so each event type stays recognisable without a coloured page */
    .email-header {
      padding: 28px 40px 24px;
      background: #ffffff;
      border-top: 4px solid #047857;
      border-bottom: 1px solid #e5e7eb;
    }
    .email-brand {
      display: block;
      margin-bottom: 20px;
      font-size: 12px;
      font-weight: 700;
      color: #047857;
      letter-spacing: 3px;
      text-transform: uppercase;
    }
    .header-title {
      margin: 0 0 6px;
      font-size: 22px;
      font-weight: 700;
      color: #111827;
      line-height: 1.35;
    }
    .header-subtitle {
      margin: 0 0 16px;
      font-size: 14px;
      color: #4b5563;
      line-height: 1.6;
    }
    .ref-badge {
      display: inline-block;
      padding: 5px 12px;
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 700;
      color: #374151;
      letter-spacing: 1px;
    }

    /* Header rule colours */
    .hdr-teal  { border-top-color: #047857; }
    .hdr-green { border-top-color: #15803d; }
    .hdr-red   { border-top-color: #b91c1c; }
    .hdr-amber { border-top-color: #b45309; }
    .hdr-blue  { border-top-color: #1d4ed8; }
    .hdr-slate { border-top-color: #4b5563; }

    /* Body */
    .email-body { padding: 32px 40px 28px; }

    /* Opening paragraph — a letter's salutation, not a callout */
    .greeting {
      font-size: 14px;
      line-height: 1.8;
      color: #374151;
      margin: 0 0 28px;
    }
    .greeting strong { color: #111827; font-weight: 700; }

    /* Plain paragraph inside the body */
    .body-text {
      font-size: 14px;
      line-height: 1.8;
      color: #374151;
      margin: 0 0 24px;
    }
    .body-text strong { color: #111827; font-weight: 700; }

    /* Section label — underline via border, not a pseudo-element (Gmail strips those) */
    .section-label {
      font-size: 13px;
      font-weight: 700;
      color: #111827;
      margin: 0 0 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #e5e7eb;
    }

    /* Info card */
    .info-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      overflow: hidden;
      margin-bottom: 24px;
    }
    .info-card-header {
      padding: 10px 18px;
      background: #f9fafb;
      border-bottom: 1px solid #e5e7eb;
    }
    .info-card-title {
      font-size: 12px;
      font-weight: 700;
      color: #4b5563;
    }

    /* Info row — table layout, not flex: Outlook and Gmail drop flex entirely */
    .info-row {
      display: table;
      width: 100%;
      padding: 11px 18px;
      border-bottom: 1px solid #f0f1f3;
    }
    .info-row:last-child { border-bottom: none; }
    .info-label {
      display: table-cell;
      font-size: 13px;
      color: #6b7280;
      font-weight: 400;
      width: 40%;
      vertical-align: top;
      padding-top: 1px;
    }
    .info-value {
      display: table-cell;
      font-size: 13px;
      color: #111827;
      font-weight: 600;
      text-align: right;
      vertical-align: top;
      line-height: 1.5;
    }
    .info-value.accent-teal  { color: #047857; }
    .info-value.accent-amber { color: #b45309; }
    .info-value.accent-red   { color: #b91c1c; }
    .info-value.accent-blue  { color: #1d4ed8; }
    .info-value.accent-green { color: #15803d; }
    .info-value.lg { font-size: 17px; font-weight: 700; }

    /* Inline text accents */
    .t-teal  { color: #047857; }
    .t-green { color: #15803d; }
    .t-amber { color: #b45309; }
    .t-red   { color: #b91c1c; }
    .t-blue  { color: #1d4ed8; }
    .t-muted { color: #6b7280; }

    /* Pickup block */
    .pickup-block {
      padding: 11px 18px;
      border-bottom: 1px solid #f0f1f3;
    }
    .pickup-block:last-child { border-bottom: none; }
    .pickup-label {
      font-size: 13px;
      color: #6b7280;
      font-weight: 400;
      margin-bottom: 4px;
    }
    .pickup-location {
      font-size: 13px;
      color: #111827;
      font-weight: 600;
      line-height: 1.5;
    }
    .pickup-region {
      font-size: 12px;
      color: #6b7280;
      font-weight: 400;
      margin-top: 3px;
    }

    /* Amount highlight box */
    .highlight-box {
      border-radius: 6px;
      padding: 18px 22px;
      margin: 0 0 24px;
      border: 1px solid;
    }
    .highlight-box .amount-label {
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    .highlight-box .amount {
      font-size: 28px;
      font-weight: 700;
      line-height: 1.2;
    }
    .highlight-box .amount-note {
      font-size: 12px;
      font-weight: 400;
      margin-top: 6px;
      line-height: 1.6;
    }

    /* Highlight variants */
    .hl-teal  { background: #f0fdf9; border-color: #a7f3d0; }
    .hl-teal .amount-label, .hl-teal .amount-note  { color: #065f46; }
    .hl-teal .amount  { color: #047857; }

    .hl-green { background: #f0fdf4; border-color: #bbf7d0; }
    .hl-green .amount-label, .hl-green .amount-note { color: #166534; }
    .hl-green .amount { color: #15803d; }

    .hl-amber { background: #fffbeb; border-color: #fde68a; }
    .hl-amber .amount-label, .hl-amber .amount-note { color: #92400e; }
    .hl-amber .amount { color: #b45309; }

    .hl-red   { background: #fef2f2; border-color: #fecaca; }
    .hl-red .amount-label, .hl-red .amount-note { color: #991b1b; }
    .hl-red .amount { color: #b91c1c; }

    .hl-blue  { background: #eff6ff; border-color: #bfdbfe; }
    .hl-blue .amount-label, .hl-blue .amount-note { color: #1e40af; }
    .hl-blue .amount { color: #1d4ed8; }

    .hl-slate { background: #f9fafb; border-color: #e5e7eb; }
    .hl-slate .amount-label, .hl-slate .amount-note { color: #4b5563; }
    .hl-slate .amount { color: #111827; }

    /* Status box */
    .status-box {
      border-radius: 6px;
      padding: 18px 22px;
      margin: 0 0 24px;
      text-align: center;
      border: 1px solid;
    }
    .status-label-small {
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    .status-value {
      font-size: 18px;
      font-weight: 700;
    }
    .st-green { background: #f0fdf4; border-color: #bbf7d0; }
    .st-green .status-label-small { color: #166534; }
    .st-green .status-value { color: #15803d; }

    .st-red { background: #fef2f2; border-color: #fecaca; }
    .st-red .status-label-small { color: #991b1b; }
    .st-red .status-value { color: #b91c1c; }

    .st-amber { background: #fffbeb; border-color: #fde68a; }
    .st-amber .status-label-small { color: #92400e; }
    .st-amber .status-value { color: #b45309; }

    .st-blue { background: #eff6ff; border-color: #bfdbfe; }
    .st-blue .status-label-small { color: #1e40af; }
    .st-blue .status-value { color: #1d4ed8; }

    /* Data table */
    .table-wrap {
      border-radius: 6px;
      overflow: hidden;
      border: 1px solid #e5e7eb;
      margin: 0 0 24px;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table thead tr { background: #f9fafb; }
    .data-table thead th {
      padding: 10px 14px;
      text-align: left;
      font-size: 12px;
      font-weight: 700;
      color: #4b5563;
      border-bottom: 1px solid #e5e7eb;
    }
    .data-table thead th:last-child { text-align: right; }
    .data-table tbody tr { border-bottom: 1px solid #f0f1f3; background: #ffffff; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody td {
      padding: 10px 14px;
      color: #374151;
      font-weight: 400;
      vertical-align: middle;
    }
    .data-table tbody td:last-child { text-align: right; }
    .cell-index { color: #9ca3af; font-size: 12px; }
    .cell-strong { font-weight: 600; color: #111827; }
    .cell-muted { color: #6b7280; }
    .badge-paid, .badge-pending, .badge-overdue {
      display: inline-block; padding: 3px 10px; border-radius: 4px;
      font-size: 11px; font-weight: 700;
    }
    .badge-paid    { background: #f0fdf4; color: #15803d; }
    .badge-pending { background: #fffbeb; color: #b45309; }
    .badge-overdue { background: #fef2f2; color: #b91c1c; }

    /* Alert box */
    .alert-box {
      padding: 14px 18px;
      margin: 0 0 20px;
      border-radius: 0 6px 6px 0;
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
      line-height: 1.75;
      margin: 0;
    }

    /* Alert variants */
    .alert-teal { background: #f0fdf9; border-left-color: #047857; }
    .alert-teal .alert-title { color: #065f46; }
    .alert-teal .alert-text  { color: #374151; }

    .alert-green { background: #f0fdf4; border-left-color: #15803d; }
    .alert-green .alert-title { color: #166534; }
    .alert-green .alert-text  { color: #374151; }

    .alert-amber { background: #fffbeb; border-left-color: #d97706; }
    .alert-amber .alert-title { color: #92400e; }
    .alert-amber .alert-text  { color: #374151; }

    .alert-red { background: #fef2f2; border-left-color: #b91c1c; }
    .alert-red .alert-title { color: #991b1b; }
    .alert-red .alert-text  { color: #374151; }

    .alert-blue { background: #eff6ff; border-left-color: #1d4ed8; }
    .alert-blue .alert-title { color: #1e40af; }
    .alert-blue .alert-text  { color: #374151; }

    .alert-neutral { background: #f9fafb; border-left-color: #9ca3af; }
    .alert-neutral .alert-title { color: #1f2937; }
    .alert-neutral .alert-text  { color: #4b5563; }

    /* CTA button */
    .cta-wrap { text-align: center; margin: 28px 0 18px; }
    .cta-btn {
      display: inline-block;
      padding: 12px 32px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
    }
    .cta-teal  { background: #047857; color: #ffffff; }
    .cta-green { background: #15803d; color: #ffffff; }
    .cta-red   { background: #b91c1c; color: #ffffff; }
    .cta-amber { background: #b45309; color: #ffffff; }
    .cta-blue  { background: #1d4ed8; color: #ffffff; }
    .cta-slate { background: #374151; color: #ffffff; }

    /* Steps — table layout so the number column survives Gmail/Outlook */
    .steps-wrap { margin: 0 0 24px; }
    .step-item {
      display: table;
      width: 100%;
      padding: 14px 0;
      border-bottom: 1px solid #f0f1f3;
    }
    .step-item:last-child { border-bottom: none; padding-bottom: 0; }
    .step-item:first-child { padding-top: 0; }
    .step-num {
      display: table-cell;
      width: 26px;
      border-radius: 999px;
      text-align: center;
      vertical-align: middle;
      font-size: 12px;
      font-weight: 700;
      line-height: 26px;
    }
    .step-teal  { background: #ecfdf5; color: #047857; }
    .step-green { background: #f0fdf4; color: #15803d; }
    .step-content { display: table-cell; padding-left: 14px; vertical-align: top; }
    .step-title { font-size: 13px; font-weight: 700; color: #111827; margin: 0 0 3px; }
    .step-desc { font-size: 13px; color: #4b5563; font-weight: 400; margin: 0; line-height: 1.65; }

    /* Contact bar */
    .contact-bar {
      padding: 12px 16px;
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      margin: 20px 0 0;
      font-size: 13px;
      color: #4b5563;
      line-height: 1.65;
    }
    .contact-bar strong { color: #111827; font-weight: 700; }

    /* Closing — "ขอแสดงความนับถือ" + team name, as on a letter */
    .signoff {
      margin: 28px 0 0;
      font-size: 14px;
      line-height: 1.8;
      color: #374151;
    }
    .signoff strong { color: #111827; font-weight: 700; }

    /* Footer */
    .email-footer {
      background: #f9fafb;
      border-top: 1px solid #e5e7eb;
      padding: 22px 40px;
    }
    .footer-logo {
      font-size: 13px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 2px;
    }
    .footer-tagline {
      font-size: 12px;
      color: #6b7280;
      margin-bottom: 14px;
      font-weight: 400;
    }
    .footer-divider { height: 1px; background: #e5e7eb; margin: 0 0 12px; }
    .footer-disclaimer { font-size: 12px; color: #6b7280; line-height: 1.8; }
    .footer-disclaimer strong { color: #374151; }

    /* Responsive */
    @media only screen and (max-width: 640px) {
      .email-outer { padding: 16px 8px 20px; }
      .email-header { padding: 24px 22px 20px; }
      .email-body { padding: 24px 22px 22px; }
      .email-footer { padding: 20px 22px; }
      .header-title { font-size: 20px; }
      .highlight-box .amount { font-size: 24px; }
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
</div>
</body>
</html>
