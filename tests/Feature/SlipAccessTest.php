<?php

namespace Tests\Feature;

use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SlipAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Force the local slip disk so slipUrl() uses the signed-route fallback.
        config(['filesystems.disks.r2_private.bucket' => null]);
        Storage::fake('local');
        Storage::disk('local')->put('slips/2026/06/test.jpg', 'SLIP-BYTES');
    }

    public function test_a_valid_signed_url_streams_the_slip(): void
    {
        $url = MediaDisk::slipUrl('slips/2026/06/test.jpg');

        $response = $this->get($url);

        $response->assertOk();
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $unsigned = route('slips.show', [
            'token' => Crypt::encryptString('slips/2026/06/test.jpg'),
        ]);

        $this->get($unsigned)->assertForbidden();
    }

    public function test_a_signed_url_cannot_escape_the_slips_folder(): void
    {
        // A correctly-signed URL whose payload points elsewhere must still 403.
        $url = URL::temporarySignedRoute(
            'slips.show',
            now()->addMinutes(5),
            ['token' => Crypt::encryptString('reviews/secret.jpg')],
        );

        $this->get($url)->assertForbidden();
    }
}
