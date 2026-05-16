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
    a { color: inherit; }

    /* ── Base ── */
    body {
      margin: 0; padding: 0;
      background-color: #f0f4f8;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
      color: #1a2e35;
      -webkit-font-smoothing: antialiased;
    }

    /* ── Wrapper ── */
    .email-outer { width: 100%; background-color: #f0f4f8; padding: 32px 16px; }
    .email-wrapper {
      max-width: 620px; margin: 0 auto;
      background: #ffffff;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 4px 32px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
    }

    /* ── Top accent bar ── */
    .accent-bar { height: 4px; width: 100%; }

    /* ── Header ── */
    .email-header { padding: 40px 40px 36px; text-align: center; }
    .logo-mark {
      display: inline-flex; align-items: center; justify-content: center;
      gap: 10px; margin-bottom: 24px;
    }
    .logo-icon {
      width: 44px; height: 44px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; line-height: 1;
    }
    .logo-text {
      font-size: 22px; font-weight: 800; letter-spacing: -0.5px;
    }
    .header-icon-wrap {
      width: 72px; height: 72px; border-radius: 50%;
      margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;
      font-size: 34px;
    }
    .header-title {
      margin: 0 0 8px; font-size: 26px; font-weight: 800;
      line-height: 1.25; letter-spacing: -0.5px;
    }
    .header-subtitle {
      margin: 0; font-size: 15px; font-weight: 500; opacity: 0.75; line-height: 1.5;
    }

    /* ── Booking ref badge ── */
    .ref-badge {
      display: inline-block; margin-top: 20px;
      padding: 8px 22px; border-radius: 100px;
      font-size: 13px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase;
    }

    /* ── Divider ── */
    .divider { height: 1px; background: #e8eef2; margin: 0 40px; }

    /* ── Body ── */
    .email-body { padding: 36px 40px; }

    /* ── Greeting ── */
    .greeting { font-size: 16px; line-height: 1.6; color: #334155; margin: 0 0 28px; }
    .greeting strong { color: #0d9488; }

    /* ── Section label ── */
    .section-label {
      font-size: 10px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;
      color: #94a3b8; margin: 0 0 14px; display: flex; align-items: center; gap: 8px;
    }
    .section-label::after {
      content: ''; flex: 1; height: 1px; background: #e8eef2;
    }

    /* ── Info card ── */
    .info-card {
      background: #f8fafc; border: 1px solid #e2e8f0;
      border-radius: 14px; padding: 20px 22px; margin-bottom: 12px;
    }
    .info-card-header {
      display: flex; align-items: center; gap: 12px; margin-bottom: 16px;
      padding-bottom: 14px; border-bottom: 1px solid #e2e8f0;
    }
    .info-card-icon {
      width: 38px; height: 38px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; flex-shrink: 0;
    }
    .info-card-title { font-size: 13px; font-weight: 700; color: #475569; }

    /* ── Row pair ── */
    .info-row {
      display: flex; justify-content: space-between; align-items: baseline;
      padding: 9px 0; border-bottom: 1px solid #f1f5f9; gap: 16px;
    }
    .info-row:last-child { border-bottom: none; padding-bottom: 0; }
    .info-row:first-child { padding-top: 0; }
    .info-label { font-size: 12px; color: #94a3b8; font-weight: 600; flex-shrink: 0; }
    .info-value { font-size: 14px; color: #1e293b; font-weight: 700; text-align: right; }
    .info-value.accent-teal { color: #0d9488; }
    .info-value.accent-amber { color: #d97706; }
    .info-value.accent-red { color: #dc2626; }
    .info-value.accent-blue { color: #2563eb; }
    .info-value.lg { font-size: 22px; }

    /* ── Highlight box ── */
    .highlight-box {
      border-radius: 14px; padding: 22px 24px; margin: 20px 0;
    }
    .highlight-box .amount-label {
      font-size: 11px; font-weight: 700; letter-spacing: 1.5px;
      text-transform: uppercase; margin-bottom: 6px; opacity: 0.7;
    }
    .highlight-box .amount {
      font-size: 32px; font-weight: 900; letter-spacing: -1px; line-height: 1;
    }
    .highlight-box .amount-note {
      font-size: 13px; font-weight: 600; margin-top: 6px; opacity: 0.75;
    }

    /* ── Table ── */
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table thead tr { background: #f1f5f9; }
    .data-table thead th {
      padding: 10px 14px; text-align: left; font-size: 10px; font-weight: 800;
      letter-spacing: 1.5px; text-transform: uppercase; color: #94a3b8;
    }
    .data-table thead th:last-child { text-align: right; }
    .data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody td { padding: 12px 14px; color: #334155; font-weight: 600; }
    .data-table tbody td:last-child { text-align: right; }
    .data-table .badge-paid {
      display: inline-block; padding: 3px 10px; border-radius: 100px;
      background: #d1fae5; color: #065f46; font-size: 10px; font-weight: 800;
      letter-spacing: 0.5px; text-transform: uppercase;
    }
    .data-table .badge-pending {
      display: inline-block; padding: 3px 10px; border-radius: 100px;
      background: #fef3c7; color: #92400e; font-size: 10px; font-weight: 800;
      letter-spacing: 0.5px; text-transform: uppercase;
    }
    .data-table .badge-overdue {
      display: inline-block; padding: 3px 10px; border-radius: 100px;
      background: #fee2e2; color: #991b1b; font-size: 10px; font-weight: 800;
      letter-spacing: 0.5px; text-transform: uppercase;
    }
    .table-wrap { border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; margin: 20px 0; }
    .table-wrap .data-table thead tr { background: #f8fafc; }

    /* ── Alert / Banner ── */
    .alert-box {
      border-radius: 14px; padding: 18px 20px; margin: 20px 0;
      display: flex; gap: 14px; align-items: flex-start;
    }
    .alert-icon { font-size: 22px; flex-shrink: 0; line-height: 1; margin-top: 1px; }
    .alert-title { font-size: 14px; font-weight: 800; margin: 0 0 4px; }
    .alert-text { font-size: 13px; font-weight: 500; line-height: 1.65; margin: 0; }

    /* ── CTA Button ── */
    .cta-wrap { text-align: center; margin: 28px 0; }
    .cta-btn {
      display: inline-block; padding: 15px 36px;
      border-radius: 100px; font-size: 15px; font-weight: 800;
      text-decoration: none; letter-spacing: 0.3px;
    }

    /* ── Steps ── */
    .steps-wrap { margin: 20px 0; }
    .step-item {
      display: flex; gap: 14px; align-items: flex-start;
      padding: 14px 0; border-bottom: 1px solid #f1f5f9;
    }
    .step-item:last-child { border-bottom: none; padding-bottom: 0; }
    .step-item:first-child { padding-top: 0; }
    .step-num {
      width: 28px; height: 28px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 800; flex-shrink: 0;
    }
    .step-content { flex: 1; }
    .step-title { font-size: 14px; font-weight: 700; color: #1e293b; margin: 0 0 2px; }
    .step-desc { font-size: 12px; color: #64748b; font-weight: 500; margin: 0; line-height: 1.5; }

    /* ── Footer ── */
    .email-footer {
      background: #f8fafc; border-top: 1px solid #e8eef2;
      padding: 28px 40px; text-align: center;
    }
    .footer-logo { font-size: 16px; font-weight: 800; color: #0d9488; margin-bottom: 8px; }
    .footer-tagline { font-size: 12px; color: #94a3b8; margin-bottom: 16px; font-weight: 500; }
    .footer-links { margin-bottom: 16px; }
    .footer-links a {
      font-size: 12px; color: #64748b; font-weight: 600;
      text-decoration: none; margin: 0 10px;
    }
    .footer-divider { height: 1px; background: #e2e8f0; margin: 16px 0; }
    .footer-disclaimer { font-size: 11px; color: #b0bec5; line-height: 1.7; }

    /* ── Responsive ── */
    @media only screen and (max-width: 640px) {
      .email-outer { padding: 16px 8px; }
      .email-header { padding: 30px 24px 26px; }
      .email-body { padding: 28px 24px; }
      .email-footer { padding: 24px; }
      .divider { margin: 0 24px; }
      .header-title { font-size: 22px; }
      .highlight-box .amount { font-size: 26px; }
    }
  </style>
</head>
<body>
<div class="email-outer">
  <div class="email-wrapper">
    {{ $slot }}
  </div>
  <!-- Spacer -->
  <div style="height:24px;"></div>
  <div style="text-align:center; font-size:11px; color:#b0bec5; padding-bottom:8px;">
    © {{ date('Y') }} Luilaykhao · ส่งจากระบบอัตโนมัติ
  </div>
</div>
</body>
</html>
