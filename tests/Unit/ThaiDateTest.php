<?php

namespace Tests\Unit;

use App\Support\ThaiDate;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ThaiDateTest extends TestCase
{
    public function test_formats_full_thai_date_with_buddhist_year(): void
    {
        $this->assertSame('25 กรกฎาคม 2569', ThaiDate::full(Carbon::parse('2026-07-25')));
        $this->assertSame('1 มกราคม 2568', ThaiDate::full(Carbon::parse('2025-01-01')));
        $this->assertSame('31 ธันวาคม 2567', ThaiDate::full(Carbon::parse('2024-12-31')));
    }

    public function test_returns_dash_for_null(): void
    {
        $this->assertSame('-', ThaiDate::full(null));
    }
}
