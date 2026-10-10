#!/usr/bin/env bash
#
# Deploy branch main ลงเครื่องนี้ด้วยขั้นตอนเดียวกันทุกครั้ง
#
#   bash /var/www/luilaykhao/deploy/deploy.sh
#
# รันในนาม user ที่ปกติใช้ git pull อยู่แล้ว (ไม่ต้องนำหน้าด้วย sudo — สคริปต์ขอ sudo
# เองเฉพาะตอนรัน artisan ในนาม www-data) ถ้าเผลอใช้ sudo ก็ยังได้ git/composer/npm
# จะถูกรันกลับในนาม user เดิม (SUDO_USER) ให้ไฟล์และสิทธิ์ GitHub ตรงกับทุกครั้ง
#
# ทำอะไรบ้าง (หยุดทันทีถ้าขั้นไหนพัง):
#   1. เช็คก่อนเริ่ม: อยู่บน main, ไม่มีไฟล์ที่ถูกแก้บนเซิร์ฟเวอร์
#   2. ดึงโค้ดใหม่ (fast-forward เท่านั้น ไม่ merge เอง)
#   3. composer install — เฉพาะเมื่อ composer.lock เปลี่ยน
#   4. npm ci (เมื่อ package-lock.json เปลี่ยน) + npm run build
#   5. php artisan migrate
#   6. ล้าง cache config/route/view/event (ไม่แตะ cache ข้อมูล — ที่นั่งที่ล็อกไว้ยังอยู่)
#   7. รีสตาร์ท Horizon ให้โหลดโค้ดใหม่
#   8. php artisan ops:doctor
#
# artisan รันในนาม www-data เสมอ (ไม่ให้ไฟล์ log/cache ใหม่เป็นของ root แล้วเว็บเขียนไม่ได้)
#
# ปรับได้ผ่าน env: APP_DIR, WEB_USER (www-data), BRANCH (main)

set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
WEB_USER="${WEB_USER:-www-data}"
BRANCH="${BRANCH:-main}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m!   %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

CURRENT_STEP="เริ่มต้น"
trap 'die "deploy หยุดที่ขั้น \"$CURRENT_STEP\" — ดูข้อความ error ด้านบน (ขั้นที่ผ่านไปแล้วไม่ถูกย้อนกลับ)"' ERR

cd "$APP_DIR" || die "ไม่พบโฟลเดอร์ $APP_DIR"
[ -f artisan ] || die "$APP_DIR ไม่ใช่โปรเจกต์ Laravel (ไม่พบไฟล์ artisan)"

ME="$(id -un)"
DEPLOYER="${SUDO_USER:-$ME}"

as_deployer() {
  if [ "$ME" = "$DEPLOYER" ]; then "$@"; else sudo -u "$DEPLOYER" -H "$@"; fi
}

artisan() {
  if [ "$ME" = "$WEB_USER" ]; then php artisan "$@"; else sudo -u "$WEB_USER" php artisan "$@"; fi
}

changed() {
  # ไฟล์นี้เปลี่ยนระหว่าง commit เดิมกับ commit ใหม่หรือไม่
  [ "$PREV" != "$NEW" ] && ! as_deployer git diff --quiet "$PREV" "$NEW" -- "$1"
}

# ---------------------------------------------------------------------------
CURRENT_STEP="เช็คก่อนเริ่ม"
step "เช็คก่อนเริ่ม ($APP_DIR, deploy ในนาม $DEPLOYER)"

for cmd in git php composer npm; do
  command -v "$cmd" >/dev/null 2>&1 || die "ไม่พบคำสั่ง $cmd"
done

if [ "$ME" != "$WEB_USER" ]; then
  # ขอรหัส sudo ครั้งเดียวตอนต้น ไม่ให้ไปถามกลางทางหลังดึงโค้ดแล้ว
  sudo -v || die "ต้องใช้ sudo ได้ (เพื่อรัน artisan ในนาม $WEB_USER)"
fi

BRANCH_NOW="$(as_deployer git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH_NOW" = "$BRANCH" ] || die "ตอนนี้อยู่บน branch $BRANCH_NOW ไม่ใช่ $BRANCH"

if [ -n "$(as_deployer git status --porcelain --untracked-files=no)" ]; then
  as_deployer git status --short --untracked-files=no
  die "มีไฟล์ถูกแก้บนเซิร์ฟเวอร์ (ด้านบน) — ย้ายการแก้ไปไว้ใน git ก่อน หรือ git checkout -- <ไฟล์> เพื่อทิ้ง"
fi

if pgrep -f "artisan queue:work" >/dev/null 2>&1; then
  warn "มี queue:work รันอยู่คู่กับ Horizon — ปิดตามขั้นตอนใน deploy/README.md (หัวข้อ supervisor)"
fi

# ---------------------------------------------------------------------------
CURRENT_STEP="ดึงโค้ด"
step "ดึงโค้ดล่าสุดจาก origin/$BRANCH"

PREV="$(as_deployer git rev-parse HEAD)"
as_deployer git fetch --quiet origin "$BRANCH"
as_deployer git merge --ff-only --quiet "origin/$BRANCH" \
  || die "ดึงโค้ดแบบ fast-forward ไม่ได้ — เซิร์ฟเวอร์มี commit ที่ไม่อยู่บน origin/$BRANCH"
NEW="$(as_deployer git rev-parse HEAD)"

if [ "$PREV" = "$NEW" ]; then
  note "ไม่มีโค้ดใหม่ ($(as_deployer git log -1 --format='%h %s')) — ทำขั้นที่เหลือต่อตามปกติ"
else
  as_deployer git log --format='    %h %s' "$PREV..$NEW"
fi

# ---------------------------------------------------------------------------
CURRENT_STEP="composer install"
if changed composer.lock || [ ! -f vendor/autoload.php ]; then
  step "composer install"
  as_deployer composer install --no-dev --optimize-autoloader --no-interaction --no-progress
else
  step "composer.lock ไม่เปลี่ยน — ข้าม composer install"
fi

# ---------------------------------------------------------------------------
CURRENT_STEP="build หน้าเว็บ"
if changed package-lock.json || [ ! -d node_modules ]; then
  step "npm ci"
  # vite อยู่ใน devDependencies — ต้องติดตั้งด้วยแม้ NODE_ENV=production
  as_deployer npm ci --include=dev --no-audit --no-fund
fi
step "npm run build"
as_deployer npm run build

# ---------------------------------------------------------------------------
CURRENT_STEP="migrate"
step "php artisan migrate"
artisan migrate --force

# ---------------------------------------------------------------------------
CURRENT_STEP="ล้าง cache"
step "ล้าง cache config / route / view / event"
artisan config:clear
artisan route:clear
artisan view:clear
artisan event:clear

# ---------------------------------------------------------------------------
CURRENT_STEP="รีสตาร์ท Horizon"
step "รีสตาร์ท Horizon (supervisor เปิดใหม่ให้เอง)"
artisan horizon:terminate
artisan queue:restart >/dev/null

for _ in $(seq 1 15); do
  sleep 2
  if artisan horizon:status >/dev/null 2>&1; then
    note "Horizon กลับมาทำงานแล้ว"
    break
  fi
done
artisan horizon:status >/dev/null 2>&1 \
  || warn "Horizon ยังไม่กลับมาใน 30 วินาที — เช็ค: sudo supervisorctl status"

# ---------------------------------------------------------------------------
trap - ERR
step "ตรวจสุขภาพระบบ (ops:doctor)"
if artisan ops:doctor; then
  printf '\n\033[1;32m✓ deploy เสร็จ — %s\033[0m\n' "$(as_deployer git log -1 --format='%h %s')"
else
  warn "deploy เสร็จแล้ว แต่ ops:doctor เจอปัญหา (ด้านบน)"
  note "ถ้าเป็น \"ยังไม่เคยเห็น\" หรือ \"รอดูได้\" ให้รอ 2-3 นาทีแล้วรันอีกครั้ง:"
  note "sudo -u $WEB_USER php artisan ops:doctor"
  exit 1
fi
