<?php

namespace App\Http\Controllers;

use App\Support\MediaDisk;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a private file — a payment slip, or a document a customer attached to
 * a booking — for disks that can't mint their own presigned URLs (e.g. the
 * local disk in development). Reached only via a temporary signed URL minted by
 * [MediaDisk::privateUrl]; the 'signed' middleware enforces the signature and
 * expiry, and the path is carried encrypted.
 */
class SlipController extends Controller
{
    public function show(string $token): StreamedResponse
    {
        try {
            $path = Crypt::decryptString($token);
        } catch (\Throwable $e) {
            abort(403);
        }

        // Defence in depth: only ever serve out of the private folders.
        if (! MediaDisk::isPrivatePath($path)) {
            abort(403);
        }

        $disk = Storage::disk(MediaDisk::slipDisk());
        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age='.(MediaDisk::SLIP_URL_TTL_MINUTES * 60),
        ]);
    }
}
