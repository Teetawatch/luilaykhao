#!/usr/bin/env bash
#
# อัปเดตแพ็กเกจของ Ubuntu (apt) บนเครื่อง prod ให้ระบบกลับมาครบเองหลังรีบูต
#
#   bash /var/www/luilaykhao/deploy/os-update.sh            # เช็ค → อัปเดต → บอกว่าต้องรีบูตไหม
#   bash /var/www/luilaykhao/deploy/os-update.sh --reboot   # เหมือนข้างบน แต่รีบูตให้เองถ้าจำเป็น
#   bash /var/www/luilaykhao/deploy/os-update.sh --check    # หลังเครื่องบูตกลับมา: ตรวจว่าครบทุกอย่าง
#
# ใส่ -y เพื่อข้ามคำถามยืนยัน รันในนาม user ไหนก็ได้ที่ใช้ sudo ได้ (สคริปต์ขอ sudo เอง)
#
# ทำอะไรบ้าง:
#   1. ล็อกคำสั่ง php ไว้ที่เวอร์ชันของ prod (จาก composer.json config.platform.php)
#      — cron และ supervisor เรียก `php` เฉย ๆ ถ้าอัปเดตลง PHP ใหม่แล้วมันไปชี้ตัวใหม่
#      ที่ไม่มี extension Horizon/scheduler จะตายเงียบ ๆ
#   2. ตั้งให้ nginx / php-fpm / mysql / redis / supervisor / cron เปิดเองตอนบูต
#   3. apt upgrade โดยเก็บไฟล์ config เดิมของเราไว้ และไม่ถามอะไรกลางทาง
#   4. ล็อก php ซ้ำ (จังหวะที่มันเปลี่ยนได้คือระหว่างอัปเดต)
#   5. ต้องรีบูต → บอก (หรือรีบูตเองถ้า --reboot) / ไม่ต้อง → รีสตาร์ท Horizon + Reverb แล้วตรวจ
#
# Horizon และ Reverb ไม่ต้องเปิดมือหลังรีบูต: supervisor ตั้ง autostart=true ไว้แล้ว
#
# ปรับได้ผ่าน env: APP_DIR, WEB_USER (www-data), PHP_TARGET (อ่านจาก composer.json),
#   APT_IPV4=1 (บังคับ apt ใช้ IPv4 — เมื่อค้างที่ที่อยู่ IPv6 ของ mirror)

set -Eeuo pipefail

main() {

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
WEB_USER="${WEB_USER:-www-data}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[1;33m!\033[0m %s\n' "$*"; WARNINGS=$((WARNINGS + 1)); }
bad()  { printf '    \033[1;31m✗\033[0m %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

MODE="update"
AUTO_REBOOT=0
ASSUME_YES=0
for arg in "$@"; do
  case "$arg" in
    --check)  MODE="check" ;;
    --reboot) AUTO_REBOOT=1 ;;
    -y|--yes) ASSUME_YES=1 ;;
    -h|--help) sed -n '2,23p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "ไม่รู้จัก $arg (ใช้ --check, --reboot, -y)" ;;
  esac
done

WARNINGS=0
FAILURES=0

cd "$APP_DIR" || die "ไม่พบโฟลเดอร์ $APP_DIR"
[ -f artisan ] || die "$APP_DIR ไม่ใช่โปรเจกต์ Laravel (ไม่พบไฟล์ artisan)"

if [ "$(id -u)" != "0" ]; then
  sudo -v || die "ต้องใช้ sudo ได้"
  SUDO="sudo"
else
  SUDO=""
fi

artisan() { $SUDO -u "$WEB_USER" php artisan "$@"; }

PHP_TARGET="${PHP_TARGET:-$(sed -n 's/.*"php": *"\([0-9]*\.[0-9]*\)\..*/\1/p' composer.json | tail -1)}"
[ -n "$PHP_TARGET" ] || die "อ่านเวอร์ชัน PHP จาก composer.json (config.platform.php) ไม่ได้ — ระบุเองด้วย PHP_TARGET=8.3"
PHP_BIN="/usr/bin/php$PHP_TARGET"

# mysql หรือ mariadb แล้วแต่เครื่อง — ใส่เฉพาะ unit ที่มีจริง
SERVICES=()
for svc in nginx "php$PHP_TARGET-fpm" mysql mariadb redis-server supervisor cron; do
  if [ -n "$(systemctl list-unit-files "$svc.service" --no-legend 2>/dev/null || true)" ]; then
    SERVICES+=("$svc")
  fi
done

pin_php() {
  [ -x "$PHP_BIN" ] || { bad "ไม่พบ $PHP_BIN — PHP $PHP_TARGET ไม่ได้ติดตั้งอยู่"; return; }
  local current
  current="$(readlink -f "$(command -v php)" 2>/dev/null || true)"
  if [ "$current" != "$(readlink -f "$PHP_BIN")" ]; then
    $SUDO update-alternatives --set php "$PHP_BIN" >/dev/null
    ok "เปลี่ยนคำสั่ง php จาก ${current:-ไม่มี} กลับมาเป็น $PHP_BIN"
  else
    ok "คำสั่ง php ชี้ไปที่ $PHP_BIN"
  fi
}

check_all() {
  step "PHP"
  local version
  version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "?")"
  if [ "$version" = "$PHP_TARGET" ]; then ok "php -v = $(php -r 'echo PHP_VERSION;')"; else bad "php เป็น $version ไม่ใช่ $PHP_TARGET — รันสคริปต์นี้แบบไม่มี --check เพื่อล็อกกลับ"; fi
  # phpredis คือ client ของคิว ไม่มีมัน Horizon เปิดไม่ขึ้น
  if php -m 2>/dev/null | grep -qx redis; then ok "extension redis โหลดอยู่"; else bad "PHP $PHP_TARGET ไม่มี extension redis (sudo apt install php$PHP_TARGET-redis)"; fi

  step "บริการของระบบ"
  local svc
  for svc in "${SERVICES[@]}"; do
    if systemctl is-active --quiet "$svc"; then ok "$svc ทำงานอยู่"; else bad "$svc ไม่ทำงาน (sudo systemctl status $svc)"; fi
  done

  step "supervisor (Horizon + Reverb)"
  local status="" program
  for _ in $(seq 1 15); do
    status="$($SUDO supervisorctl status 2>/dev/null || true)"
    if printf '%s\n' "$status" | grep -q '^luilaykhao-horizon .*RUNNING' \
      && printf '%s\n' "$status" | grep -q '^reverb.*RUNNING'; then
      break
    fi
    sleep 2
  done
  for program in luilaykhao-horizon reverb; do
    if printf '%s\n' "$status" | grep -q "^$program.*RUNNING"; then ok "$program RUNNING"; else bad "$program ไม่ RUNNING (sudo supervisorctl status)"; fi
  done

  step "หน้าเว็บ"
  local app_url code
  app_url="$($SUDO sed -n 's/^APP_URL=//p' .env | tr -d '"'"'" | tail -1)"
  if [ -n "$app_url" ]; then
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$app_url" 2>/dev/null || echo "000")"
    if [ "$code" -ge 200 ] && [ "$code" -lt 400 ]; then ok "$app_url ตอบ $code"; else bad "$app_url ตอบ $code"; fi
  else
    warn "ไม่พบ APP_URL ใน .env — ข้ามการเช็คหน้าเว็บ"
  fi

  step "Reverb (reverb:check)"
  if artisan reverb:check; then :; else bad "Laravel ส่ง event เข้า Reverb ไม่ได้ (ด้านบน)"; fi

  step "ตรวจสุขภาพระบบ (ops:doctor)"
  if artisan ops:doctor; then :; else
    warn "ops:doctor เจอปัญหา (ด้านบน) — ถ้าเป็นงานรายวันที่ \"ยังไม่เคยเห็น\" หลังรีบูต รอ 2-3 นาทีแล้วรันอีกครั้ง"
  fi

  if [ -f /var/run/reboot-required ]; then
    warn "เครื่องยังรอรีบูต (มีแพ็กเกจที่ต้องรีบูตถึงจะมีผล)"
  fi

  printf '\n'
  if [ "$FAILURES" -gt 0 ]; then
    printf '\033[1;31m✗ มีปัญหา %d ข้อ (ด้านบน)\033[0m\n' "$FAILURES"
    exit 1
  fi
  printf '\033[1;32m✓ ระบบกลับมาครบแล้ว\033[0m%s\n' "$([ "$WARNINGS" -gt 0 ] && printf ' (มีข้อควรดู %d ข้อ)' "$WARNINGS")"
}

# ---------------------------------------------------------------------------
if [ "$MODE" = "check" ]; then
  check_all
  exit 0
fi

step "เช็คก่อนอัปเดต"

pin_php

for svc in "${SERVICES[@]}"; do
  if [ "$(systemctl is-enabled "$svc" 2>/dev/null || true)" = "enabled" ]; then
    ok "$svc เปิดเองตอนบูต"
  else
    $SUDO systemctl enable "$svc" >/dev/null 2>&1 && ok "$svc ตั้งให้เปิดเองตอนบูตแล้ว" \
      || bad "ตั้ง $svc ให้เปิดเองตอนบูตไม่ได้"
  fi
done

FREE_MB="$(df -Pm / | awk 'NR==2 {print $4}')"
if [ "$FREE_MB" -lt 1024 ]; then
  die "พื้นที่ดิสก์เหลือ ${FREE_MB}MB — ต้องมีอย่างน้อย 1GB ก่อนอัปเดต"
fi
ok "ดิสก์เหลือ ${FREE_MB}MB"

if systemctl is-enabled --quiet unattended-upgrades 2>/dev/null; then
  ok "unattended-upgrades เปิดอยู่ (อัปเดตความปลอดภัยให้เองทุกวัน)"
else
  warn "unattended-upgrades ปิดอยู่ — เปิดด้วย: sudo dpkg-reconfigure -plow unattended-upgrades"
fi

[ "$FAILURES" -eq 0 ] || die "เช็คก่อนอัปเดตไม่ผ่าน (ด้านบน)"

# apt ใช้ได้ทีละตัว — หลังเปิดเครื่อง/ล็อกอินใหม่ ๆ unattended-upgrades มักถือ lock อยู่
# ไม่บอกก่อน หน้าจอจะดูเหมือนค้างเฉย ๆ
wait_for_apt() {
  local holders i
  for i in $(seq 1 120); do
    holders="$($SUDO fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock 2>/dev/null | tr -s ' ' || true)"
    [ -z "${holders// /}" ] && return 0
    if [ "$i" -eq 1 ]; then
      note "apt กำลังถูกใช้โดยโปรแกรมอื่น (ปกติคือ unattended-upgrades ที่อัปเดตเองอยู่) — รอให้เสร็จ:"
      # shellcheck disable=SC2086
      ps -o pid=,etime=,cmd= -p $(echo $holders | tr ' ' ',') 2>/dev/null | sed 's/^/      /' || true
    fi
    sleep 5
  done
  die "รอ apt 10 นาทีแล้วยังไม่ว่าง — ดูว่าใครถือ: sudo fuser -v /var/lib/dpkg/lock-frontend"
}

# ตัดการเชื่อมต่อที่ไม่ตอบใน 30 วินาที แทนที่จะรอเงียบ ๆ (เช่น IPv6 ของ VPS ใช้ไม่ได้จริง)
APT_NET=(-o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 -o Acquire::Retries=1)
if [ "${APT_IPV4:-0}" = "1" ]; then APT_NET+=(-o Acquire::ForceIPv4=true); fi

step "ดึงรายการแพ็กเกจ"
wait_for_apt
$SUDO apt-get update "${APT_NET[@]}" \
  || die "apt-get update ไม่สำเร็จ (ด้านบน) — ถ้าค้างที่ที่อยู่ IPv6 ลอง: APT_IPV4=1 bash $0"
UPGRADABLE="$(apt list --upgradable 2>/dev/null | grep -v '^Listing' || true)"
if [ -z "$UPGRADABLE" ]; then
  note "ไม่มีแพ็กเกจให้อัปเดต"
else
  note "อัปเดตได้ $(printf '%s\n' "$UPGRADABLE" | wc -l | tr -d ' ') แพ็กเกจ ตัวที่กระทบระบบ:"
  printf '%s\n' "$UPGRADABLE" \
    | grep -E '^(php|mysql|mariadb|redis|nginx|supervisor|linux-image|openssl|libssl)' \
    | sed 's/^/      /' || note "  (ไม่มี — มีแต่แพ็กเกจทั่วไป)"
fi

if [ "$ASSUME_YES" -eq 0 ] && [ -n "$UPGRADABLE" ]; then
  printf '\n'
  note "ก่อนไปต่อ: snapshot VPS ไว้แล้ว และตอนนี้ไม่มีรอบกำลังเดินทาง"
  note "(ระหว่างรีบูต เช็คอิน / GPS รถ / การ์ดหน้าล็อกสกรีน จะหยุด 1-2 นาที)"
  read -r -p "    อัปเดตเลยไหม? [y/N] " answer || answer=""
  [[ "$answer" =~ ^[Yy]$ ]] || die "ยกเลิก — ยังไม่ได้เปลี่ยนอะไรนอกจากขั้นเช็คด้านบน"
fi

if [ -n "$UPGRADABLE" ]; then
  step "apt upgrade"
  # --force-confold: แพ็กเกจมีไฟล์ config ใหม่ ให้เก็บของเราไว้ ไม่ถาม ไม่เขียนทับ
  # NEEDRESTART_MODE=a: รีสตาร์ทบริการที่ใช้ไลบรารีเก่าเอง ไม่เด้งหน้าจอถาม
  # --with-new-pkgs: ลง kernel ใหม่ได้ (upgrade เฉย ๆ จะ "kept back") แต่ไม่ลบแพ็กเกจใด
  wait_for_apt
  $SUDO env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a \
    apt-get -y -q --with-new-pkgs "${APT_NET[@]}" \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
    upgrade
  $SUDO env DEBIAN_FRONTEND=noninteractive apt-get -y -q autoremove >/dev/null || true
fi

step "หลังอัปเดต"
pin_php
[ "$FAILURES" -eq 0 ] || die "PHP ไม่อยู่ในสภาพที่ Horizon ใช้ได้ (ด้านบน) — แก้ก่อนรีบูต"

if [ -f /var/run/reboot-required ]; then
  note "ต้องรีบูตเพื่อให้แพ็กเกจเหล่านี้มีผล:"
  sed 's/^/      /' /var/run/reboot-required.pkgs 2>/dev/null | sort -u || true
  if [ "$AUTO_REBOOT" -eq 1 ]; then
    note "รีบูตใน 10 วินาที (Ctrl+C เพื่อยกเลิก) เครื่องกลับมาแล้วรัน:"
    note "bash $APP_DIR/deploy/os-update.sh --check"
    sleep 10
    $SUDO systemctl reboot
    exit 0
  fi
  printf '\n\033[1;33m! อัปเดตเสร็จ — ต้องรีบูต\033[0m\n'
  note "sudo reboot"
  note "เครื่องกลับมาแล้วรัน: bash $APP_DIR/deploy/os-update.sh --check"
  exit 0
fi

# ไม่ต้องรีบูต แต่ Horizon/Reverb ยังรันบน PHP ตัวที่โหลดไว้ก่อนอัปเดต
step "รีสตาร์ท Horizon + Reverb ให้ใช้ PHP ที่เพิ่งอัปเดต"
artisan horizon:terminate
$SUDO supervisorctl restart 'reverb:*' >/dev/null || $SUDO supervisorctl restart reverb >/dev/null || true

check_all
}

main "$@"
