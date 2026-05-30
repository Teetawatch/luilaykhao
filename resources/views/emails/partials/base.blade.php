<!DOCTYPE html>
<html lang="th" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
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

    /* Base */
    body {
      margin: 0; padding: 0;
      background-color: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
      color: #1e293b;
      -webkit-font-smoothing: antialiased;
    }

    /* Outer wrapper */
    .email-outer {
      width: 100%;
      background-color: #f1f5f9;
      padding: 40px 16px 32px;
    }
    .email-wrapper {
      max-width: 600px;
      margin: 0 auto;
      background: #ffffff;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 8px rgba(0,0,0,0.06);
    }

    /* Header */
    .email-header {
      padding: 40px 40px 32px;
      text-align: center;
    }
    .email-brand {
      display: block;
      margin-bottom: 24px;
      font-size: 11px;
      font-weight: 800;
      color: rgba(255,255,255,0.65);
      letter-spacing: 4px;
      text-transform: uppercase;
    }
    .header-title {
      margin: 0 0 8px;
      font-size: 24px;
      font-weight: 800;
      color: #ffffff;
      line-height: 1.25;
      letter-spacing: -0.3px;
    }
    .header-subtitle {
      margin: 0 0 22px;
      font-size: 14px;
      color: rgba(255,255,255,0.80);
      line-height: 1.55;
    }
    .ref-badge {
      display: inline-block;
      padding: 5px 16px;
      background: rgba(0,0,0,0.15);
      border: 1px solid rgba(255,255,255,0.25);
      border-radius: 4px;
      font-size: 11px;
      font-weight: 700;
      color: #ffffff;
      letter-spacing: 2.5px;
      text-transform: uppercase;
    }

    /* Body */
    .email-body {
      padding: 36px 40px 32px;
    }

    /* Greeting block */
    .greeting {
      font-size: 14px;
      line-height: 1.7;
      color: #475569;
      margin: 0 0 28px;
      padding: 16px 20px;
      background: #f8fafc;
      border-left: 3px solid #0d9488;
      border-radius: 0 6px 6px 0;
    }
    .greeting strong { color: #1e293b; }

    /* Section label */
    .section-label {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: #94a3b8;
      margin: 0 0 12px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section-label::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #e2e8f0;
    }

    /* Info card */
    .info-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 24px;
    }
    .info-card-header {
      padding: 12px 20px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }
    .info-card-title {
      font-size: 11px;
      font-weight: 800;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.8px;
    }

    /* Info row */
    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 12px 20px;
      border-bottom: 1px solid #f1f5f9;
      gap: 20px;
    }
    .info-row:last-child { border-bottom: none; }
    .info-label {
      font-size: 12px;
      color: #94a3b8;
      font-weight: 500;
      flex-shrink: 0;
      padding-top: 1px;
      min-width: 110px;
    }
    .info-value {
      font-size: 13px;
      color: #1e293b;
      font-weight: 600;
      text-align: right;
      line-height: 1.5;
    }
    .info-value.accent-teal  { color: #0d9488; }
    .info-value.accent-amber { color: #d97706; }
    .info-value.accent-red   { color: #dc2626; }
    .info-value.accent-blue  { color: #2563eb; }
    .info-value.accent-green { color: #059669; }
    .info-value.lg { font-size: 18px; font-weight: 700; }

    /* Pickup block */
    .pickup-block {
      padding: 12px 20px;
      border-bottom: 1px solid #f1f5f9;
    }
    .pickup-block:last-child { border-bottom: none; }
    .pickup-label {
      font-size: 12px;
      color: #94a3b8;
      font-weight: 500;
      margin-bottom: 4px;
    }
    .pickup-location {
      font-size: 13px;
      color: #1e293b;
      font-weight: 600;
      line-height: 1.45;
    }
    .pickup-region {
      font-size: 12px;
      color: #d97706;
      font-weight: 500;
      margin-top: 2px;
    }

    /* Amount highlight box */
    .highlight-box {
      border-radius: 8px;
      padding: 20px 24px;
      margin: 0 0 24px;
      border: 1px solid;
      border-left-width: 4px;
    }
    .highlight-box .amount-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .highlight-box .amount {
      font-size: 32px;
      font-weight: 800;
      letter-spacing: -0.5px;
      line-height: 1;
    }
    .highlight-box .amount-note {
      font-size: 12px;
      font-weight: 500;
      margin-top: 6px;
    }

    /* Status box */
    .status-box {
      border-radius: 8px;
      padding: 24px;
      margin: 0 0 24px;
      text-align: center;
      border: 1px solid;
      border-left-width: 4px;
    }
    .status-label-small {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .status-value {
      font-size: 20px;
      font-weight: 800;
    }

    /* Data table */
    .table-wrap {
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #e2e8f0;
      margin: 0 0 24px;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table thead tr { background: #f8fafc; }
    .data-table thead th {
      padding: 10px 14px;
      text-align: left;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: #94a3b8;
      border-bottom: 1px solid #e2e8f0;
    }
    .data-table thead th:last-child { text-align: right; }
    .data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody td {
      padding: 11px 14px;
      color: #374151;
      font-weight: 500;
      vertical-align: middle;
    }
    .data-table tbody td:last-child { text-align: right; }
    .badge-paid {
      display: inline-block; padding: 3px 10px; border-radius: 3px;
      background: #d1fae5; color: #065f46;
      font-size: 10px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase;
    }
    .badge-pending {
      display: inline-block; padding: 3px 10px; border-radius: 3px;
      background: #fef3c7; color: #92400e;
      font-size: 10px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase;
    }
    .badge-overdue {
      display: inline-block; padding: 3px 10px; border-radius: 3px;
      background: #fee2e2; color: #991b1b;
      font-size: 10px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase;
    }

    /* Alert box — left-border accent, no icon */
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
      line-height: 1.65;
      margin: 0;
    }

    /* CTA button */
    .cta-wrap { text-align: center; margin: 28px 0 18px; }
    .cta-btn {
      display: inline-block;
      padding: 14px 36px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 700;
      color: #ffffff;
      text-decoration: none;
      letter-spacing: 0.2px;
    }

    /* Steps */
    .steps-wrap { margin: 0 0 24px; }
    .step-item {
      display: flex;
      gap: 14px;
      align-items: flex-start;
      padding: 14px 0;
      border-bottom: 1px solid #f1f5f9;
    }
    .step-item:last-child { border-bottom: none; padding-bottom: 0; }
    .step-item:first-child { padding-top: 0; }
    .step-num {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 800;
      flex-shrink: 0;
      margin-top: 1px;
    }
    .step-content { flex: 1; }
    .step-title { font-size: 13px; font-weight: 700; color: #1e293b; margin: 0 0 3px; }
    .step-desc { font-size: 12px; color: #64748b; font-weight: 400; margin: 0; line-height: 1.5; }

    /* Contact bar */
    .contact-bar {
      padding: 13px 18px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      margin: 20px 0 0;
      font-size: 13px;
      color: #64748b;
      line-height: 1.5;
    }
    .contact-bar strong { color: #1e293b; }

    /* Footer */
    .email-footer {
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      padding: 24px 40px;
      text-align: center;
    }
    .footer-logo {
      font-size: 11px;
      font-weight: 800;
      color: #0d9488;
      margin-bottom: 3px;
      letter-spacing: 3px;
      text-transform: uppercase;
    }
    .footer-tagline {
      font-size: 12px;
      color: #94a3b8;
      margin-bottom: 16px;
      font-weight: 400;
    }
    .footer-divider { height: 1px; background: #e2e8f0; margin: 0 0 14px; }
    .footer-disclaimer { font-size: 11px; color: #b0bec5; line-height: 1.8; }

    /* Responsive */
    @media only screen and (max-width: 640px) {
      .email-outer { padding: 20px 10px 24px; }
      .email-header { padding: 32px 24px 28px; }
      .email-body { padding: 28px 24px 24px; }
      .email-footer { padding: 20px 20px; }
      .header-title { font-size: 21px; }
      .highlight-box .amount { font-size: 26px; }
      .info-row { flex-direction: column; gap: 3px; }
      .info-value { text-align: left; }
      .info-label { min-width: unset; }
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
