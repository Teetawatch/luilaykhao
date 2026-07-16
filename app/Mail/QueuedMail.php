<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;

/**
 * Base for every mail this app sends.
 *
 * Mail goes out on the queue. A plain Mailable sent with Mail::to()->send() opens
 * an SMTP connection to Brevo inline, so creating a booking blocked the customer's
 * response on two round-trips to an external host — for mail whose delivery is
 * already best-effort (callers log failures and carry on).
 *
 * ShouldQueueAfterCommit rather than ShouldQueue: mail queued inside a DB
 * transaction must not be picked up by a worker before the row it renders exists,
 * which would fail the job with a ModelNotFoundException. Callers today all send
 * after their transaction closes; this keeps that from being load-bearing.
 */
abstract class QueuedMail extends Mailable implements ShouldQueueAfterCommit
{
    /** SMTP blips are transient — retry before writing the mail off. */
    public $tries = 3;

    /** @return int[] seconds to wait between attempts */
    public function backoff(): array
    {
        return [30, 120];
    }
}
