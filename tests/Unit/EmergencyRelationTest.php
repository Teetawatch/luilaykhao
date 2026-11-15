<?php

namespace Tests\Unit;

use App\Support\EmergencyRelation;
use PHPUnit\Framework\TestCase;

/** ความสัมพันธ์ผู้ติดต่อฉุกเฉิน — เดาจากช่อง "ผู้ติดต่อฉุกเฉิน" ของใบจองก่อนมีช่องแยก */
class EmergencyRelationTest extends TestCase
{
    public function test_guesses_relation_words_written_into_the_contact_field(): void
    {
        $cases = [
            'คุณแม่' => 'แม่',
            'แม่' => 'แม่',
            'สมศรี ใจดี (แม่)' => 'แม่',
            'สมศรี ใจดี / มารดา' => 'แม่',
            'นายสมชาย ใจดี - บิดา' => 'พ่อ',
            'แม่สมศรี' => 'แม่',
            'คุณแม่สมศรี' => 'แม่',
            'พี่สาวต้น' => 'พี่น้อง',
            'ต้น (น้องชาย)' => 'พี่น้อง',
            'ภรรยา' => 'ภรรยา',
            'เมีย' => 'ภรรยา',
            'แฟน' => 'แฟน',
            'เพื่อน: เอ' => 'เพื่อน',
            'ยาย' => 'ญาติ',
            'Mom' => 'แม่',
            'John Smith (husband)' => 'สามี',
        ];

        foreach ($cases as $contact => $expected) {
            $this->assertSame($expected, EmergencyRelation::guess($contact), $contact);
        }
    }

    public function test_names_are_not_mistaken_for_a_relation(): void
    {
        // "ตา" "อา" "ลูก" "พี่" "น้อง" อยู่ในชื่อ/ชื่อเล่นได้ — ต้องตรงทั้งคำเท่านั้น
        foreach (['สมศรี ใจดี', 'ตาล', 'อาทิตย์ แสงทอง', 'ลูกหมี', 'พี่ต้น', 'น้องเมย์', 'แม่น', '', null] as $contact) {
            $this->assertSame('', EmergencyRelation::guess($contact), (string) $contact);
        }
    }

    public function test_what_the_customer_picked_wins_over_the_guess(): void
    {
        $this->assertSame('เพื่อน', EmergencyRelation::resolve('เพื่อน', 'คุณแม่'));
        $this->assertSame('แม่', EmergencyRelation::resolve('  ', 'คุณแม่'));
        $this->assertSame('', EmergencyRelation::resolve(null, 'สมศรี'));
    }

    public function test_clean_trims_and_nulls_blank(): void
    {
        $this->assertNull(EmergencyRelation::clean('   '));
        $this->assertSame('น้า ฝั่งแม่', EmergencyRelation::clean('  น้า   ฝั่งแม่ '));
        $this->assertSame(EmergencyRelation::MAX_LENGTH, mb_strlen(EmergencyRelation::clean(str_repeat('ก', 80))));
    }
}
