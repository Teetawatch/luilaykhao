<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Support\MediaDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Audit & recover payment slips that became unviewable after the private disk
 * was switched to R2. Each slip_path in the DB points at a relative path
 * (slips/YYYY/MM/x.ext), but the actual file may still be sitting on a disk
 * other than the one slipUrl() now serves from (r2_private) — typically the
 * old local 'local' disk or the public 'r2'/'public' disk from a half-finished
 * migration. This walks every referenced slip, finds where the file really is,
 * and copies it onto the private disk so the presigned URL resolves again.
 *
 * Default run is read-only (audit). Pass --apply to actually copy files.
 */
class RecoverSlipsToPrivate extends Command
{
    protected $signature = 'slips:recover {--apply : คัดลอกไฟล์จริง (ค่าเริ่มต้นเป็นการตรวจสอบอย่างเดียว)}';

    protected $description = 'ตรวจสอบ slip_path ทั้งหมดในฐานข้อมูล หาว่าไฟล์อยู่ disk ไหน แล้วคัดลอกขึ้น private disk (r2_private) ให้ครบ';

    public function handle(): int
    {
        $target = MediaDisk::slipDisk();

        // Candidate source disks to look for a stray slip on, in priority order.
        // Skip the target itself and any disk that isn't configured.
        $candidates = array_values(array_filter(
            ['local', 'public', 'r2', 'r2_private'],
            fn (string $d) => $d !== $target && $this->diskConfigured($d),
        ));

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('AUDIT MODE — ไม่มีการเขียนไฟล์ใดๆ (ใส่ --apply เพื่อคัดลอกจริง)');
        }
        $this->line("Target disk (ปลายทาง): {$target}");
        $this->line('Source disks ที่จะค้นหา: '.implode(', ', $candidates));
        $this->newLine();

        // The slip disks run with throw=false, so a failed upload (e.g. the
        // token can't write the bucket) makes writeStream() silently return
        // false. Force this disk to throw while we copy so failures are loud and
        // we never report a write that didn't happen.
        config(["filesystems.disks.{$target}.throw" => true]);
        Storage::forgetDisk($target);
        $to = Storage::disk($target);

        $alreadyThere = 0; // อยู่บน r2_private แล้ว — ปกติดี
        $recovered = 0;    // คัดลอกขึ้นสำเร็จ (หรือจะคัดลอกถ้า --apply)
        $candidate = 0;    // เจอบน source พร้อมคัดลอก (โหมด audit)
        $missing = [];     // หาไม่เจอที่ไหนเลย — กู้ไม่ได้
        $failed = [];      // เจอ source แต่เขียนขึ้น target ไม่สำเร็จ
        $foundOn = [];     // นับว่าเจอจาก disk ไหนบ้าง
        $firstError = null;

        foreach ($this->slipPaths() as [$path, $owner]) {
            // HEAD on the private bucket returns 200 for an existing object but
            // 403 (not 404) for a missing one when the token lacks ListBucket —
            // and Flysystem turns that 403 into an exception. existsOn() swallows
            // it and reports false, which is exactly what we want: a file we
            // can't confirm is there gets re-fetched from a source disk below.
            if ($this->existsOn($target, $path)) {
                $alreadyThere++;

                continue;
            }

            $src = null;
            foreach ($candidates as $disk) {
                if ($this->existsOn($disk, $path)) {
                    $src = $disk;
                    break;
                }
            }

            if ($src === null) {
                $missing[] = "{$owner}  ({$path})";

                continue;
            }

            if (! $apply) {
                $candidate++;
                $foundOn[$src] = ($foundOn[$src] ?? 0) + 1;

                continue;
            }

            try {
                $stream = Storage::disk($src)->readStream($path);
                $to->writeStream($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $recovered++;
                $foundOn[$src] = ($foundOn[$src] ?? 0) + 1;
            } catch (\Throwable $e) {
                $failed[] = "{$owner}  ({$path})";
                $firstError ??= $e->getMessage();
            }
        }

        $this->newLine();
        $this->info("อยู่บน '{$target}' อยู่แล้ว: {$alreadyThere}");

        if ($apply) {
            $this->info("คัดลอกขึ้น '{$target}' สำเร็จ: {$recovered}");
        } else {
            $this->info("คัดลอกได้ (รอ --apply): {$candidate}");
        }
        foreach ($foundOn as $disk => $count) {
            $this->line("    └ เจอบน '{$disk}': {$count}");
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error('เขียนขึ้น "'.$target.'" ไม่สำเร็จ: '.count($failed));
            $this->error('สาเหตุ (รายแรก): '.$firstError);
            $this->warn('มักเกิดจาก R2 API token ไม่มีสิทธิ์เขียน bucket นี้ — ตรวจสอบ token ให้เป็น Object Read & Write ที่ครอบคลุม bucket แล้วรันใหม่');
        }

        if ($missing !== []) {
            $this->newLine();
            $this->error('หาไฟล์ไม่เจอที่ไหนเลย (กู้ไม่ได้ ต้องให้ลูกค้าส่งใหม่): '.count($missing));
            foreach ($missing as $line) {
                $this->line('    ✗ '.$line);
            }
        }

        if (! $apply && $candidate > 0) {
            $this->newLine();
            $this->warn("รัน `php artisan slips:recover --apply` เพื่อคัดลอก {$candidate} ไฟล์ขึ้น '{$target}' จริง");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Yield every [relative slip path, human label] referenced in the DB,
     * skipping null/empty and the known "0" corruption from failed uploads.
     *
     * @return iterable<array{0:string,1:string}>
     */
    private function slipPaths(): iterable
    {
        foreach (Booking::query()->whereNotNull('slip_path')->orWhereNotNull('balance_slip_path')
            ->get(['booking_ref', 'slip_path', 'balance_slip_path']) as $b) {
            if ($this->usable($b->slip_path)) {
                yield [$b->slip_path, "Booking {$b->booking_ref} (slip)"];
            }
            if ($this->usable($b->balance_slip_path)) {
                yield [$b->balance_slip_path, "Booking {$b->booking_ref} (balance)"];
            }
        }

        foreach (InstallmentPayment::query()->whereNotNull('slip_path')
            ->with('booking:id,booking_ref')->get() as $i) {
            if ($this->usable($i->slip_path)) {
                yield [$i->slip_path, 'Booking '.(optional($i->booking)->booking_ref ?? '?')." (งวด {$i->installment_no})"];
            }
        }
    }

    /** A slip_path we can actually resolve to a file (not empty, not the "0" garbage). */
    private function usable(?string $path): bool
    {
        return $path !== null && $path !== '' && str_starts_with($path, 'slips/');
    }

    private function diskConfigured(string $disk): bool
    {
        if (in_array($disk, ['local', 'public'], true)) {
            return true;
        }

        return (bool) config("filesystems.disks.{$disk}.bucket");
    }

    private function existsOn(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
