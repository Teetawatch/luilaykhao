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
    /* ── Reset ── */
    *, *::before, *::after { box-sizing: border-box; }
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; display: block; }
    a { color: inherit; text-decoration: none; }

    /* ── Base ── */
    body {
      margin: 0; padding: 0;
      background-color: #eef2f6;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
      color: #1a2e35;
      -webkit-font-smoothing: antialiased;
    }

    /* ── Outer wrapper ── */
    .email-outer {
      width: 100%;
      background-color: #eef2f6;
      padding: 40px 16px 32px;
    }
    .email-wrapper {
      max-width: 600px;
      margin: 0 auto;
      background: #ffffff;
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 8px 40px rgba(0,0,0,0.10), 0 1px 4px rgba(0,0,0,0.06);
    }

    /* ── Header ── */
    .email-header {
      padding: 40px 40px 36px;
      text-align: center;
      position: relative;
    }

    /* Logo row */
    .logo-row {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 28px;
      padding: 8px 18px;
      background: rgba(255,255,255,0.18);
      border: 1px solid rgba(255,255,255,0.30);
      border-radius: 100px;
    }
    .logo-leaf {
      font-size: 18px;
      line-height: 1;
    }
    .logo-name {
      font-size: 15px;
      font-weight: 800;
      color: #ffffff;
      letter-spacing: 0.3px;
    }

    /* Header icon */
    .header-icon-wrap {
      width: 76px;
      height: 76px;
      border-radius: 50%;
      background: rgba(255,255,255,0.20);
      border: 2px solid rgba(255,255,255,0.35);
      margin: 0 auto 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 36px;
      line-height: 1;
    }
    .header-title {
      margin: 0 0 10px;
      font-size: 28px;
      font-weight: 900;
      color: #ffffff;
      line-height: 1.2;
      letter-spacing: -0.5px;
    }
    .header-subtitle {
      margin: 0 0 24px;
      font-size: 15px;
      font-weight: 500;
      color: rgba(255,255,255,0.88);
      line-height: 1.55;
    }

    /* Booking ref badge */
    .ref-badge {
      display: inline-block;
      padding: 7px 22px;
      border-radius: 100px;
      background: rgba(255,255,255,0.18);
      border: 1.5px solid rgba(255,255,255,0.40);
      font-size: 12px;
      font-weight: 800;
      color: #ffffff;
      letter-spacing: 2px;
      text-transform: uppercase;
    }

    /* ── Body ── */
    .email-body {
      padding: 40px 40px 36px;
    }

    /* Greeting */
    .greeting {
      font-size: 15px;
      line-height: 1.7;
      color: #374151;
      margin: 0 0 32px;
      padding: 20px 22px;
      background: #f8fafc;
      border-left: 3px solid #0d9488;
      border-radius: 0 12px 12px 0;
    }
    .greeting strong { color: #0d9488; }

    /* ── Section label ── */
    .section-label {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      color: #94a3b8;
      margin: 0 0 16px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section-label::after {
      content: '';
      flex: 1;
      height: 1px;
      background: linear-gradient(90deg, #e2e8f0, transparent);
    }

    /* ── Info card ── */
    .info-card {
      background: #ffffff;
      border: 1.5px solid #e8edf3;
      border-radius: 16px;
      overflow: hidden;
      margin-bottom: 28px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .info-card-header {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 16px 22px;
      background: #f8fafc;
      border-bottom: 1.5px solid #e8edf3;
    }
    .info-card-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
    }
    .info-card-title {
      font-size: 13px;
      font-weight: 800;
      color: #374151;
      letter-spacing: 0.2px;
    }

    /* ── Info row ── */
    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 14px 22px;
      border-bottom: 1px solid #f1f5f9;
      gap: 20px;
    }
    .info-row:last-child {
      border-bottom: none;
    }
    .info-label {
      font-size: 12px;
      color: #94a3b8;
      font-weight: 600;
      flex-shrink: 0;
      padding-top: 2px;
      min-width: 110px;
    }
    .info-value {
      font-size: 14px;
      color: #1e293b;
      font-weight: 700;
      text-align: right;
      line-height: 1.5;
    }
    .info-value.accent-teal  { color: #0d9488; }
    .info-value.accent-amber { color: #d97706; }
    .info-value.accent-red   { color: #dc2626; }
    .info-value.accent-blue  { color: #2563eb; }
    .info-value.lg { font-size: 20px; }

    /* ── Pickup block (special layout) ── */
    .pickup-block {
      padding: 14px 22px;
      border-bottom: 1px solid #f1f5f9;
    }
    .pickup-block:last-child { border-bottom: none; }
    .pickup-label {
      font-size: 12px;
      color: #94a3b8;
      font-weight: 600;
      margin-bottom: 6px;
    }
    .pickup-location {
      font-size: 14px;
      color: #1e293b;
      font-weight: 700;
      line-height: 1.45;
    }
    .pickup-region {
      font-size: 12px;
      color: #d97706;
      font-weight: 600;
      margin-top: 3px;
    }

    /* ── Highlight / Amount box ── */
    .highlight-box {
      border-radius: 16px;
      padding: 24px 28px;
      margin: 0 0 28px;
    }
    .highlight-box .amount-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      margin-bottom: 8px;
      opacity: 0.75;
    }
    .highlight-box .amount {
      font-size: 36px;
      font-weight: 900;
      letter-spacing: -1px;
      line-height: 1;
    }
    .highlight-box .amount-note {
      font-size: 13px;
      font-weight: 600;
      margin-top: 8px;
      opacity: 0.80;
    }

    /* ── Status highlight (for booking status email) ── */
    .status-box {
      border-radius: 16px;
      padding: 28px 24px;
      margin: 0 0 28px;
      text-align: center;
    }
    .status-icon-large {
      font-size: 48px;
      line-height: 1;
      margin-bottom: 12px;
    }
    .status-label-small {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      margin-bottom: 6px;
      opacity: 0.7;
    }
    .status-value {
      font-size: 22px;
      font-weight: 900;
      letter-spacing: -0.5px;
    }

    /* ── Data table ── */
    .table-wrap {
      border-radius: 14px;
      overflow: hidden;
      border: 1.5px solid #e8edf3;
      margin: 0 0 28px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table thead tr { background: #f8fafc; }
    .data-table thead th {
      padding: 12px 16px;
      text-align: left;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: #94a3b8;
      border-bottom: 1.5px solid #e8edf3;
    }
    .data-table thead th:last-child { text-align: right; }
    .data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody td {
      padding: 13px 16px;
      color: #374151;
      font-weight: 600;
      vertical-align: middle;
    }
    .data-table tbody td:last-child { text-align: right; }
    .badge-paid {
      display: inline-block; padding: 4px 12px; border-radius: 100px;
      background: #d1fae5; color: #065f46;
      font-size: 10px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;
    }
    .badge-pending {
      display: inline-block; padding: 4px 12px; border-radius: 100px;
      background: #fef3c7; color: #92400e;
      font-size: 10px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;
    }
    .badge-overdue {
      display: inline-block; padding: 4px 12px; border-radius: 100px;
      background: #fee2e2; color: #991b1b;
      font-size: 10px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;
    }

    /* ── Alert / Banner ── */
    .alert-box {
      border-radius: 14px;
      padding: 18px 22px;
      margin: 0 0 20px;
      display: flex;
      gap: 16px;
      align-items: flex-start;
    }
    .alert-icon-wrap {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
      margin-top: 1px;
    }
    .alert-title {
      font-size: 13px;
      font-weight: 800;
      margin: 0 0 5px;
      letter-spacing: 0.1px;
    }
    .alert-text {
      font-size: 13px;
      font-weight: 500;
      line-height: 1.65;
      margin: 0;
    }

    /* ── CTA Button ── */
    .cta-wrap { text-align: center; margin: 32px 0 20px; }
    .cta-btn {
      display: inline-block;
      padding: 16px 40px;
      border-radius: 100px;
      font-size: 15px;
      font-weight: 800;
      color: #ffffff;
      text-decoration: none;
      letter-spacing: 0.2px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.18);
    }

    /* ── Steps ── */
    .steps-wrap { margin: 0 0 28px; }
    .step-item {
      display: flex;
      gap: 16px;
      align-items: flex-start;
      padding: 16px 0;
      border-bottom: 1px solid #f1f5f9;
    }
    .step-item:last-child { border-bottom: none; padding-bottom: 0; }
    .step-item:first-child { padding-top: 0; }
    .step-num {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 900;
      flex-shrink: 0;
      margin-top: 1px;
    }
    .step-content { flex: 1; }
    .step-title { font-size: 14px; font-weight: 800; color: #1e293b; margin: 0 0 4px; }
    .step-desc { font-size: 12px; color: #64748b; font-weight: 500; margin: 0; line-height: 1.55; }

    /* ── Contact bar ── */
    .contact-bar {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 14px 20px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      margin: 24px 0 0;
      font-size: 13px;
      color: #64748b;
      font-weight: 600;
    }
    .contact-bar strong { color: #1e293b; }

    /* ── Footer ── */
    .email-footer {
      background: #f8fafc;
      border-top: 1.5px solid #e8edf3;
      padding: 28px 40px;
      text-align: center;
    }
    .footer-logo {
      font-size: 17px;
      font-weight: 900;
      color: #0d9488;
      margin-bottom: 4px;
      letter-spacing: -0.3px;
    }
    .footer-tagline {
      font-size: 12px;
      color: #94a3b8;
      margin-bottom: 20px;
      font-weight: 500;
    }
    .footer-divider { height: 1px; background: #e2e8f0; margin: 0 0 16px; }
    .footer-disclaimer { font-size: 11px; color: #b0bec5; line-height: 1.8; }

    /* ── Responsive ── */
    @media only screen and (max-width: 640px) {
      .email-outer { padding: 20px 10px 24px; }
      .email-header { padding: 32px 24px 28px; }
      .email-body { padding: 30px 24px 28px; }
      .email-footer { padding: 24px 20px; }
      .header-title { font-size: 23px; }
      .highlight-box .amount { font-size: 28px; }
      .info-row { flex-direction: column; gap: 4px; }
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
  <div style="height:24px;"></div>
  <div style="text-align:center; font-size:11px; color:#b0bec5; padding-bottom:8px;">
    &copy; {{ date('Y') }} Luilaykhao &middot; ส่งจากระบบอัตโนมัติ
  </div>
</div>
</body>
</html>
