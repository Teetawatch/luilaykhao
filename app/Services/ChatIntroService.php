<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatMessageUpdated;
use App\Models\ChatMemberIntro;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Models\User;

/**
 * "มาแนะนำตัวกัน" — ลูกทริปบอกว่ามาจากไหน เคยเดินป่าไหม และอีกนิดหน่อย
 * ละลายพฤติกรรมคนแปลกหน้าก่อนวันเดินทาง
 *
 * ทุกช่องไม่บังคับ (อยากบอกเท่าไรก็เท่านั้น) แต่ต้องมีอย่างน้อยหนึ่งช่อง
 * การ์ดถูกโพสต์เป็นข้อความของคนนั้นเอง (ไม่ใช่ข้อความระบบ) body เป็นข้อความสำรอง
 * ให้เว็บ/แอปรุ่นเก่า · แก้ทีหลัง = แก้ข้อความเดิม ไม่โพสต์ใหม่ · ไม่ยิง push
 * (แนะนำตัวไม่ใช่เรื่องด่วน 30 คนแนะนำตัวไม่ควรเด้ง 30 ครั้ง)
 */
class ChatIntroService
{
    public function __construct(
        private ChatService $chat,
        private ContentFilterService $filter,
    ) {}

    /**
     * @param  array{hometown?: ?string, experience?: ?string, note?: ?string}  $data
     */
    public function upsert(User $user, TripSchedule $schedule, array $data): ChatMemberIntro
    {
        $hometown = $this->clean($data['hometown'] ?? null);
        $note = $this->clean($data['note'] ?? null);
        $experience = $data['experience'] ?? null;
        if ($experience !== null && ! array_key_exists($experience, ChatMemberIntro::EXPERIENCES)) {
            $experience = null;
        }

        if ($hometown === null && $note === null && $experience === null) {
            throw new \Exception('บอกอย่างน้อยหนึ่งอย่างนะครับ');
        }

        foreach ([$hometown, $note] as $text) {
            if ($rejected = $this->filter->check($text)) {
                throw new \Exception($rejected);
            }
        }

        $intro = ChatMemberIntro::firstOrNew(['schedule_id' => $schedule->id, 'user_id' => $user->id]);
        $intro->fill(['hometown' => $hometown, 'experience' => $experience, 'note' => $note]);

        $body = $this->fallbackBody($user, $intro);
        $message = $intro->message_id ? ChatMessage::find($intro->message_id) : null;

        // ข้อความเดิมถูกลบไปแล้ว (หรือยังไม่เคยโพสต์) — โพสต์ใหม่
        if (! $message || $message->is_deleted || $message->hidden_at !== null) {
            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'sender_role' => $this->chat->senderRole($user, $schedule),
                'body' => $body,
            ]);
            $intro->message_id = $message->id;
            $intro->save();

            broadcast(new ChatMessageSent($message->fresh()))->toOthers();
            $this->chat->markRead($user, $schedule, $message->id);

            return $intro;
        }

        $intro->save();
        $message->forceFill(['body' => $body])->save();
        broadcast(new ChatMessageUpdated($message->fresh()))->toOthers();

        return $intro;
    }

    public function mine(User $user, TripSchedule $schedule): ?ChatMemberIntro
    {
        return ChatMemberIntro::where('schedule_id', $schedule->id)->where('user_id', $user->id)->first();
    }

    /**
     * การ์ดของข้อความ (ถ้าเป็นข้อความแนะนำตัว) — ข้อความที่ถูกลบไม่ส่ง
     *
     * @return array<string, mixed>|null
     */
    public function forMessage(ChatMessage $message): ?array
    {
        if ($message->is_deleted || $message->user_id === null || $message->sender_role === 'system') {
            return null;
        }

        $intro = $message->relationLoaded('memberIntro') ? $message->memberIntro : $message->memberIntro()->first();

        return $intro ? $this->present($intro) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatMemberIntro $intro): array
    {
        return [
            'id' => $intro->id,
            'user_id' => (int) $intro->user_id,
            'hometown' => $intro->hometown,
            'experience' => $intro->experience,
            'experience_label' => $intro->experienceLabel(),
            'note' => $intro->note,
        ];
    }

    private function fallbackBody(User $user, ChatMemberIntro $intro): string
    {
        $name = $user->nickname ?: $user->name;
        $lines = ["👋 สวัสดีครับ/ค่ะ {$name} มาแนะนำตัว"];
        if ($intro->hometown) {
            $lines[] = "มาจาก {$intro->hometown}";
        }
        if ($label = $intro->experienceLabel()) {
            $lines[] = $label;
        }
        if ($intro->note) {
            $lines[] = $intro->note;
        }

        return implode("\n", $lines);
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' ? null : $text;
    }
}
