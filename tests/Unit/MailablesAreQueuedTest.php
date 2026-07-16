<?php

namespace Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use ReflectionClass;
use Tests\TestCase;

/**
 * Mail must never go out inline. Mail::to()->send() on a plain Mailable opens an
 * SMTP connection to Brevo during the request — that used to add seconds to every
 * booking. Extending QueuedMail is what keeps it on the queue, so pin the rule
 * here rather than trusting each new mailable to remember.
 */
class MailablesAreQueuedTest extends TestCase
{
    /** @return class-string<Mailable>[] */
    private function mailables(): array
    {
        return collect(glob(app_path('Mail/*.php')))
            ->map(fn ($path) => 'App\\Mail\\'.basename($path, '.php'))
            ->filter(fn ($class) => class_exists($class) && ! (new ReflectionClass($class))->isAbstract())
            ->values()
            ->all();
    }

    public function test_there_are_mailables_to_check(): void
    {
        // Guard against the glob silently matching nothing and passing vacuously.
        $this->assertNotEmpty($this->mailables());
    }

    public function test_every_mailable_is_queued_and_commit_safe(): void
    {
        foreach ($this->mailables() as $class) {
            $this->assertTrue(
                is_subclass_of($class, ShouldQueueAfterCommit::class),
                "{$class} must extend App\\Mail\\QueuedMail so it is queued after commit "
                .'instead of blocking the request on an SMTP handshake.'
            );
        }
    }
}
