<?php

declare(strict_types=1);

namespace App\Modules\Shared\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The message sent by `axispay:mail-test`. Not ShouldQueue: the command
 * decides between sending now and queueing (`--queue`).
 */
final class MailTestMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $sentAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config()->string('app.name').' mail test');
    }

    public function content(): Content
    {
        $app = e(config()->string('app.name'));
        $sentAt = e($this->sentAt);

        return new Content(htmlString: "<p>This is a test e-mail from {$app}, requested with <code>axispay:mail-test</code> at {$sentAt} (UTC).</p><p>If you received it, outgoing mail works. No action is needed.</p>");
    }
}
