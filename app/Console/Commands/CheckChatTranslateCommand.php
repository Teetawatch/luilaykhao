<?php

namespace App\Console\Commands;

use App\Services\ChatTranslationMeter;
use App\Services\ChatTranslationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ลองแปลข้อความแชทตัวอย่างจริงกับรุ่นที่ตั้งไว้ (ANTHROPIC_TRANSLATE_MODEL) แล้ว
 * พิมพ์คำแปล + ค่าใช้จ่ายให้ตรวจด้วยตา — ใช้ก่อน/หลังเปลี่ยนรุ่นโมเดล
 *
 * เรียก API จริง (นับเข้างบเดือนตามจริง ตัวอย่างชุดเต็มราว 0.1 บาทบน Haiku 5.5)
 * --message="..." ลองข้อความของตัวเองได้
 */
class CheckChatTranslateCommand extends Command
{
    protected $signature = 'chat-translate:check
        {--message=* : ข้อความที่จะลองแปล (ใส่ซ้ำได้) — ไม่ใส่ = ใช้ชุดตัวอย่าง}
        {--lang= : ภาษาปลายทาง th|en|zh — ไม่ใส่ = ไทยแปลเป็น en+zh, ภาษาอื่นแปลเป็น th}';

    protected $description = 'Translate sample chat messages with the configured model and show the result and cost.';

    /** ข้อความแชทจริงแบบที่เจอบ่อย — ภาษาพูด ศัพท์ทริป เวลา ราคา ชื่อสถานที่ */
    private const SAMPLES = [
        'พรุ่งนี้รถตู้ออกตีห้าตรงนะครับ เจอกันที่จุดนัดรวมพลหน้าเซเว่น',
        'ใครยังไม่ได้โอนค่ามัดจำ 1,500 บาท รบกวนโอนภายในวันศุกร์นี้นะคะ',
        'ฝนตกหนักบนดอยอินทนนท์ เตรียมเสื้อกันฝนกับรองเท้ากันลื่นมาด้วยครับ',
        'สตาฟขอแวะปั๊มสิบนาทีนะ ใครจะเข้าห้องน้ำรีบเลย 555',
        'Is there a place to charge my phone at the campsite?',
        'I am allergic to peanuts, can you tell the cook please?',
        '请问明天几点在酒店门口集合？',
    ];

    public function handle(ChatTranslationService $translations, ChatTranslationMeter $meter): int
    {
        if (blank(config('services.anthropic.key'))) {
            $this->error('✗ ไม่มี ANTHROPIC_API_KEY ในเครื่องนี้ — ระบบแปลไม่ทำงาน');

            return self::FAILURE;
        }

        $model = (string) config('services.anthropic.translate_model');
        $this->line('');
        $this->line("  รุ่น       {$model}");
        $this->line(sprintf('  งบเดือนนี้  ฿%s / %s', number_format($meter->monthSpendThb(), 2), number_format($meter->capThb())));
        $this->line('');

        $before = (float) DB::table('chat_translation_usage')->sum('cost_usd');
        $messages = $this->option('message') ?: self::SAMPLES;
        $failed = 0;

        foreach ($messages as $body) {
            $targets = $this->option('lang')
                ? [$this->option('lang')]
                : (ChatTranslationService::scriptOf($body) === 'th' ? ['en', 'zh'] : ['th']);

            $this->line("  <fg=gray>ต้นฉบับ</>  {$body}");
            foreach ($targets as $lang) {
                try {
                    $answer = $translations->preview($body, $lang);
                    $text = $answer['same_language'] ? '(เป็นภาษานี้อยู่แล้ว)' : $answer['translation'];
                    $this->line("  <info>→ {$lang}</info>     {$text}");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->line("  <error>→ {$lang} ✗</error>   {$e->getMessage()}");
                }
            }
            $this->line('');
        }

        $spentThb = ((float) DB::table('chat_translation_usage')->sum('cost_usd') - $before) * (float) config('chat_translation.usd_thb', 36);
        $this->line(sprintf('  ค่าใช้จ่ายรอบนี้ ≈ ฿%s (นับเข้างบเดือนแล้ว)', number_format($spentThb, 4)));

        if ($failed > 0) {
            $this->error("✗ แปลไม่สำเร็จ {$failed} ครั้ง — ดูรายละเอียดใน storage/logs (ChatTranslation)");

            return self::FAILURE;
        }

        $this->info('✓ แปลครบทุกข้อความ — ตรวจคุณภาพคำแปลข้างบนด้วยตา');

        return self::SUCCESS;
    }
}
