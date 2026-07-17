<!DOCTYPE html>
<html lang="th" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="color-scheme" content="light only" />
  <meta name="supported-color-schemes" content="light only" />
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
      background-color: #eef1f5;
      font-family: 'Sarabun', 'Leelawadee UI', 'Tahoma', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      color: #1e293b;
      -webkit-font-smoothing: antialiased;
    }

    /* Outer wrapper */
    .email-outer {
      width: 100%;
      background-color: #eef1f5;
      padding: 40px 16px 32px;
    }
    .email-wrapper {
      max-width: 600px;
      margin: 0 auto;
      background: #ffffff;
      border: 1px solid #dfe5ec;
      border-radius: 4px;
      overflow: hidden;
    }

    /* Header */
    .email-header {
      padding: 36px 40px 32px;
      text-align: center;
    }
    .email-brand {
      display: block;
      margin-bottom: 22px;
      font-size: 11px;
      font-weight: 700;
      color: #ffffff;
      opacity: 0.70;
      letter-spacing: 4px;
      text-transform: uppercase;
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
      opacity: 0.80;
      line-height: 1.55;
    }
    .ref-badge {
      display: inline-block;
      padding: 6px 16px;
      border: 1px solid rgba(255,255,255,0.35);
      border-radius: 3px;
      font-size: 11px;
      font-weight: 700;
      color: #ffffff;
      letter-spacing: 2.5px;
      text-transform: uppercase;
    }

    /* Body */
    .email-body { padding: 36px 40px 32px; }

    /* Greeting block */
    .greeting {
      font-size: 14px;
      line-height: 1.75;
      color: #475569;
      margin: 0 0 28px;
      padding: 16px 20px;
      background: #f6f8fa;
      border-left: 3px solid #115e59;
      border-radius: 0 3px 3px 0;
    }
    .greeting strong { color: #1e293b; font-weight: 700; }

    /* Section label — underline via border, not a pseudo-element (Gmail strips those) */
    .section-label {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: #8794a5;
      margin: 0 0 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #e3e8ee;
    }

    /* Info card */
    .info-card {
      background: #ffffff;
      border: 1px solid #dfe5ec;
      border-radius: 4px;
      overflow: hidden;
      margin-bottom: 24px;
    }
    .info-card-header {
      padding: 11px 20px;
      background: #f6f8fa;
      border-bottom: 1px solid #dfe5ec;
    }
    .info-card-title {
      font-size: 10px;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 1.2px;
    }

    /* Info row — table layout, not flex: Outlook and Gmail drop flex entirely */
    .info-row {
      display: table;
      width: 100%;
      padding: 12px 20px;
      border-bottom: 1px solid #f0f3f7;
    }
    .info-row:last-child { border-bottom: none; }
    .info-label {
      display: table-cell;
      font-size: 12px;
      color: #8794a5;
      font-weight: 400;
      width: 40%;
      vertical-align: top;
      padding-top: 1px;
    }
    .info-value {
      display: table-cell;
      font-size: 13px;
      color: #1e293b;
      font-weight: 600;
      text-align: right;
      vertical-align: top;
      line-height: 1.5;
    }
    .info-value.accent-teal  { color: #115e59; }
    .info-value.accent-amber { color: #92400e; }
    .info-value.accent-red   { color: #991b1b; }
    .info-value.accent-blue  { color: #1e40af; }
    .info-value.accent-green { color: #14532d; }
    .info-value.lg { font-size: 18px; font-weight: 700; }

    /* Pickup block */
    .pickup-block {
      padding: 12px 20px;
      border-bottom: 1px solid #f0f3f7;
    }
    .pickup-block:last-child { border-bottom: none; }
    .pickup-label {
      font-size: 12px;
      color: #8794a5;
      font-weight: 400;
      margin-bottom: 4px;
    }
    .pickup-location {
      font-size: 13px;
      color: #1e293b;
      font-weight: 600;
      line-height: 1.5;
    }
    .pickup-region {
      font-size: 12px;
      color: #92400e;
      font-weight: 400;
      margin-top: 3px;
    }

    /* Amount highlight box */
    .highlight-box {
      border-radius: 4px;
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

    /* Status box */
    .status-box {
      border-radius: 4px;
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

    /* Data table */
    .table-wrap {
      border-radius: 4px;
      overflow: hidden;
      border: 1px solid #dfe5ec;
      margin: 0 0 24px;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table thead tr { background: #f6f8fa; }
    .data-table thead th {
      padding: 10px 14px;
      text-align: left;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: #8794a5;
      border-bottom: 1px solid #dfe5ec;
    }
    .data-table thead th:last-child { text-align: right; }
    .data-table tbody tr { border-bottom: 1px solid #f0f3f7; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody td {
      padding: 11px 14px;
      color: #374151;
      font-weight: 400;
      vertical-align: middle;
    }
    .data-table tbody td:last-child { text-align: right; }
    .badge-paid, .badge-pending, .badge-overdue {
      display: inline-block; padding: 3px 10px; border-radius: 3px;
      font-size: 10px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase;
    }
    .badge-paid    { background: #dcfce7; color: #14532d; }
    .badge-pending { background: #fef3c7; color: #78350f; }
    .badge-overdue { background: #fee2e2; color: #7f1d1d; }

    /* Alert box */
    .alert-box {
      padding: 14px 18px;
      margin: 0 0 20px;
      border-radius: 0 3px 3px 0;
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

    /* CTA button */
    .cta-wrap { text-align: center; margin: 28px 0 18px; }
    .cta-btn {
      display: inline-block;
      padding: 14px 36px;
      border-radius: 3px;
      font-size: 14px;
      font-weight: 700;
      color: #ffffff;
      text-decoration: none;
    }

    /* Steps — table layout so the number column survives Gmail/Outlook */
    .steps-wrap { margin: 0 0 24px; }
    .step-item {
      display: table;
      width: 100%;
      padding: 14px 0;
      border-bottom: 1px solid #f0f3f7;
    }
    .step-item:last-child { border-bottom: none; padding-bottom: 0; }
    .step-item:first-child { padding-top: 0; }
    .step-num {
      display: table-cell;
      width: 28px;
      border-radius: 14px;
      text-align: center;
      vertical-align: middle;
      font-size: 12px;
      font-weight: 700;
      line-height: 28px;
    }
    .step-content { display: table-cell; padding-left: 14px; vertical-align: top; }
    .step-title { font-size: 13px; font-weight: 700; color: #1e293b; margin: 0 0 3px; }
    .step-desc { font-size: 12px; color: #64748b; font-weight: 400; margin: 0; line-height: 1.6; }

    /* Contact bar */
    .contact-bar {
      padding: 13px 18px;
      background: #f6f8fa;
      border: 1px solid #dfe5ec;
      border-radius: 3px;
      margin: 20px 0 0;
      font-size: 13px;
      color: #64748b;
      line-height: 1.6;
    }
    .contact-bar strong { color: #1e293b; font-weight: 700; }

    /* Footer */
    .email-footer {
      background: #f6f8fa;
      border-top: 1px solid #dfe5ec;
      padding: 24px 40px;
      text-align: center;
    }
    .footer-logo {
      font-size: 11px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 4px;
      letter-spacing: 3px;
      text-transform: uppercase;
    }
    .footer-tagline {
      font-size: 12px;
      color: #8794a5;
      margin-bottom: 16px;
      font-weight: 400;
    }
    .footer-divider { height: 1px; background: #e3e8ee; margin: 0 0 14px; }
    .footer-disclaimer { font-size: 11px; color: #94a3b8; line-height: 1.9; }

    /* Responsive */
    @media only screen and (max-width: 640px) {
      .email-outer { padding: 20px 10px 24px; }
      .email-header { padding: 30px 22px 26px; }
      .email-body { padding: 26px 22px 24px; }
      .email-footer { padding: 20px; }
      .header-title { font-size: 20px; }
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
  <div style="text-align:center; font-size:11px; color:#94a3b8; padding-bottom:8px;">
    &copy; {{ date('Y') }} Luilaykhao &middot; ส่งจากระบบอัตโนมัติ
  </div>
</div>
</body>
</html>
