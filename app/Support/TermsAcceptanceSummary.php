<?php

namespace App\Support;

use App\Models\Booking;

/**
 * หลักฐานการยอมรับเงื่อนไขของใบจองหนึ่งใบ ในรูปที่ API/อีเมล/ใบเสร็จใช้ร่วมกัน
 *
 * ใบจองมีได้สามสภาพ แยกไว้ให้ชัดเพราะน้ำหนักเป็นหลักฐานต่างกันมาก:
 * - recorded     มีแถวใน booking_term_acceptances (ข้อความ ช่องทาง เครื่อง)
 * - version_only จองก่อนมีตารางนั้น รู้แค่เวลาและเลขฉบับ ข้อความดึงจากคลัง
 * - none         ไม่มีการกดยอมรับ (แอดมินจองแทน, แอปรุ่นก่อน)
 */
class TermsAcceptanceSummary
{
    /**
     * @param  bool  $withDevice  รวม IP/เครื่อง/ลายนิ้วมือ — สำหรับทีมงานเท่านั้น
     * @return array<string, mixed>
     */
    public static function forBooking(Booking $booking, bool $withDevice = false): array
    {
        $acceptance = $booking->relationLoaded('termAcceptance')
            ? $booking->termAcceptance
            : $booking->termAcceptance()->first();

        if ($acceptance) {
            return [
                'status' => 'recorded',
                'version' => $acceptance->terms_version,
                'accepted_at' => $acceptance->accepted_at?->toISOString(),
                'channel' => $acceptance->channel,
                'channel_label' => $acceptance->channelLabel(),
                'lines' => array_values((array) $acceptance->terms_lines),
            ] + ($withDevice ? [
                'accepted_by' => [
                    'user_id' => $acceptance->user_id,
                    'name' => $acceptance->accepted_by_name,
                    'email' => $acceptance->accepted_by_email,
                    'phone' => $acceptance->accepted_by_phone,
                ],
                'ip_address' => $acceptance->ip_address,
                'user_agent' => $acceptance->user_agent,
                'hash' => $acceptance->terms_hash,
                'intact' => $acceptance->isIntact(),
            ] : []);
        }

        if ($booking->terms_accepted_at && $booking->terms_version) {
            return [
                'status' => 'version_only',
                'version' => $booking->terms_version,
                'accepted_at' => $booking->terms_accepted_at->toISOString(),
                'channel' => null,
                'channel_label' => null,
                'lines' => LegalPolicy::archivedBookingTerms($booking->terms_version) ?? [],
            ];
        }

        return ['status' => 'none'];
    }
}
