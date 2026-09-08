<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_it_reduces_every_written_form_to_the_same_number(): void
    {
        foreach (['0812345678', '081-234-5678', '081 234 5678', '+66812345678', '66812345678', '0066812345678'] as $written) {
            $this->assertSame('0812345678', PhoneNumber::normalise($written), $written);
        }
    }

    public function test_a_number_too_short_to_identify_anyone_is_not_a_match(): void
    {
        $this->assertSame('', PhoneNumber::normalise('5678'));
        $this->assertFalse(PhoneNumber::matches('5678', '5678'));
        $this->assertFalse(PhoneNumber::matches(null, null));
    }

    public function test_matching_ignores_formatting(): void
    {
        $this->assertTrue(PhoneNumber::matches('081-234-5678', '+66 81 234 5678'));
        $this->assertFalse(PhoneNumber::matches('0812345678', '0812345679'));
    }

    public function test_variants_cover_the_forms_people_actually_type(): void
    {
        $variants = PhoneNumber::variants('0812345678');

        $this->assertContains('0812345678', $variants);
        $this->assertContains('081-234-5678', $variants);
        $this->assertContains('+66812345678', $variants);
    }

    public function test_last_four_digits_ignore_separators(): void
    {
        $this->assertSame('5678', PhoneNumber::last4('081-234-5678'));
        $this->assertTrue(PhoneNumber::endsWithLast4('081 234 5678', '5678'));
        $this->assertFalse(PhoneNumber::endsWithLast4('0812345678', '567'));
    }
}
