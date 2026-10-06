<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * คำขอแบบไม่บอกชื่อบนรถ — ทีมงานเห็นแค่จำนวนต่อเรื่อง ไม่เห็นว่าใคร
 * (user_id เก็บไว้กันกดรัวและแจ้งกลับเป็นรายคน แต่ไม่เคยส่งออก)
 */
class ChatStopRequest extends Model
{
    public const KIND_TOILET = 'toilet';

    /**
     * เรื่องที่ขอได้ — [ข้อความบนปุ่ม, emoji, ข้อความถึงทีมงาน, ข้อความประกาศตอนรับทราบ]
     * ห้องน้ำเป็นเรื่องเดียวที่ "ค้างจนกว่าจะรับทราบ" เพราะต้องหาที่จอด ที่เหลือ
     * เป็นความรู้สึก ณ ตอนนั้น จึงหมดอายุเองใน COMFORT_TTL_MINUTES
     */
    public const KINDS = [
        'toilet' => ['label' => 'ขอแวะห้องน้ำ', 'emoji' => '🚻', 'staff' => 'มีคนขอแวะห้องน้ำ', 'ack' => null],
        'too_cold' => ['label' => 'แอร์หนาวไป', 'emoji' => '🥶', 'staff' => 'มีคนบอกว่าแอร์หนาวไป', 'ack' => 'ปรับแอร์ให้อุ่นขึ้นแล้วนะครับ'],
        'too_hot' => ['label' => 'แอร์ร้อนไป', 'emoji' => '🥵', 'staff' => 'มีคนบอกว่าแอร์ร้อนไป', 'ack' => 'ปรับแอร์ให้เย็นขึ้นแล้วนะครับ'],
        'too_fast' => ['label' => 'ขับเร็วไป', 'emoji' => '🐢', 'staff' => 'มีคนบอกว่ารถขับเร็วไป', 'ack' => 'บอกคนขับให้ชะลอลงแล้วนะครับ'],
        'music_down' => ['label' => 'ขอเบาเพลงหน่อย', 'emoji' => '🔉', 'staff' => 'มีคนขอให้เบาเพลงลง', 'ack' => 'เบาเพลงลงแล้วนะครับ'],
    ];

    public const COMFORT_TTL_MINUTES = 60;

    protected $fillable = ['schedule_id', 'user_id', 'kind', 'urgent', 'acknowledged_at'];

    protected function casts(): array
    {
        return [
            'urgent' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }
}
