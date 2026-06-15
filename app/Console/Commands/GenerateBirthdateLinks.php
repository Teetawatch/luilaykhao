<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Mint per-customer "fill in your birth date" links and export them to a CSV so
 * staff can mail-merge / send via Line / SMS. Targets only customers missing a
 * birth date by default so we don't pester people who already provided one.
 */
class GenerateBirthdateLinks extends Command
{
    protected $signature = 'birthdate:links {--all : Include customers who already have a birth date}';

    protected $description = 'Generate public birth-date links for customers and export them to a CSV.';

    public function handle(): int
    {
        $query = User::role('customer');
        if (! $this->option('all')) {
            $query->whereNull('birth_date');
        }

        $users = $query->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->info('ไม่มีลูกค้าที่ต้องสร้างลิงก์ (ทุกคนมีวันเกิดแล้ว)');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                $user->id,
                $user->name,
                $user->phone,
                $user->email,
                $user->birthdateUrl(),
            ];
        }

        $bom = "\u{FEFF}";
        $lines = ['"id","ชื่อ","เบอร์โทร","อีเมล","ลิงก์กรอกวันเกิด"'];
        foreach ($rows as $row) {
            $lines[] = collect($row)
                ->map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"')
                ->implode(',');
        }

        $path = 'birthdate-links-'.now()->format('Ymd_His').'.csv';
        Storage::put($path, $bom.implode("\n", $lines));

        $this->info('สร้างลิงก์ให้ลูกค้า '.count($rows).' คน');
        $this->line('ไฟล์ CSV: '.Storage::path($path));

        return self::SUCCESS;
    }
}
