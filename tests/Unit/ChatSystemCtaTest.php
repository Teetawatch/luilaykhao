<?php

namespace Tests\Unit;

use App\Services\ChatService;
use App\Services\ScheduleRoomService;
use App\Services\TripDepartureService;
use PHPUnit\Framework\TestCase;

/**
 * ปุ่มใต้ข้อความระบบในห้องแชท — ข้อความที่พูดถึงหน้าอื่น ("ติดตามรถ", "กำหนดการ",
 * "หน้าวันเดินทาง") ต้องกดเปิดหน้านั้นได้จากแชทเลย
 */
class ChatSystemCtaTest extends TestCase
{
    public function test_exact_keys_map_to_their_button(): void
    {
        $this->assertSame('intro', ChatService::ctaFor('intro_3d'));
        $this->assertSame('contacts', ChatService::ctaFor('contacts_end'));
        $this->assertSame('track_van', ChatService::ctaFor(TripDepartureService::CHAT_KEY));
        $this->assertSame('trip_day', ChatService::ctaFor('departure_soon'));
        $this->assertSame('itinerary', ChatService::ctaFor('itinerary_2d'));
        $this->assertSame('pickup', ChatService::ctaFor('pickup_eve'));
        $this->assertSame('review', ChatService::ctaFor('trip_end'));
        $this->assertSame('trip_feed', ChatService::ctaFor('photos_expiring'));
    }

    public function test_numbered_keys_map_by_prefix(): void
    {
        $this->assertSame('track_van', ChatService::ctaFor('pickup_arrived_12'));
        $this->assertSame('trip_day', ChatService::ctaFor('pickup_mates_12'));
        $this->assertSame('itinerary', ChatService::ctaFor('day_brief_3'));
        $this->assertSame('rooms', ChatService::ctaFor(ScheduleRoomService::CHAT_KEY_PREFIX.'abc123'));
    }

    public function test_other_messages_have_no_button(): void
    {
        $this->assertNull(ChatService::ctaFor(null));
        $this->assertNull(ChatService::ctaFor(''));
        $this->assertNull(ChatService::ctaFor('countdown_7d'));
        $this->assertNull(ChatService::ctaFor('member_joined:5'));
        $this->assertNull(ChatService::ctaFor('birthday:9'));
    }
}
