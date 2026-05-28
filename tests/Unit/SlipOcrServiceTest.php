<?php

namespace Tests\Unit;

use App\Services\SlipOcrService;
use PHPUnit\Framework\TestCase;

class SlipOcrServiceTest extends TestCase
{
    public function test_extracts_plain_json(): void
    {
        $json = '{"status":"success","amount":1500.00}';

        $this->assertSame(
            ['status' => 'success', 'amount' => 1500.00],
            SlipOcrService::extractJson($json),
        );
    }

    public function test_extracts_json_wrapped_in_markdown_fence(): void
    {
        $content = "```json\n{\"status\":\"success\",\"amount\":1500}\n```";

        $parsed = SlipOcrService::extractJson($content);

        $this->assertIsArray($parsed);
        $this->assertSame('success', $parsed['status']);
        $this->assertSame(1500, $parsed['amount']);
    }

    public function test_extracts_json_wrapped_in_bare_fence(): void
    {
        $content = "```\n{\"status\":\"success\"}\n```";

        $this->assertSame(
            ['status' => 'success'],
            SlipOcrService::extractJson($content),
        );
    }

    public function test_extracts_json_with_prose_preamble(): void
    {
        $content = "Here is the extracted data:\n{\"status\":\"success\",\"amount\":99}";

        $parsed = SlipOcrService::extractJson($content);

        $this->assertSame('success', $parsed['status']);
        $this->assertSame(99, $parsed['amount']);
    }

    public function test_strips_trailing_commas(): void
    {
        $content = '{"status":"success","amount":50,}';

        $parsed = SlipOcrService::extractJson($content);

        $this->assertIsArray($parsed);
        $this->assertSame('success', $parsed['status']);
    }

    public function test_returns_null_for_empty_content(): void
    {
        $this->assertNull(SlipOcrService::extractJson(''));
        $this->assertNull(SlipOcrService::extractJson('   '));
    }

    public function test_returns_null_when_no_json_present(): void
    {
        $this->assertNull(SlipOcrService::extractJson('I cannot read this slip.'));
    }

    public function test_reattached_prefill_brace_parses(): void
    {
        // Mimics the production path: API response starts after the assistant
        // prefill of "{", so the service prepends it before parsing.
        $apiResponseBody = '"status":"success","amount":2500.50}';
        $reattached = '{'.$apiResponseBody;

        $parsed = SlipOcrService::extractJson($reattached);

        $this->assertSame('success', $parsed['status']);
        $this->assertSame(2500.50, $parsed['amount']);
    }
}
