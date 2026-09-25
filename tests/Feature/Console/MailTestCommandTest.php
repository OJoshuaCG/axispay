<?php

declare(strict_types=1);

use App\Modules\Shared\Mail\MailTestMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

it('sends a test e-mail synchronously and prints the mail settings without the password', function (): void {
    Mail::fake();
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'mail.example.test',
        'mail.mailers.smtp.port' => 465,
        'mail.mailers.smtp.scheme' => 'smtps',
        'mail.mailers.smtp.username' => 'noreply@example.test',
        'mail.mailers.smtp.password' => 'super-secret-password',
        'mail.from.address' => 'noreply@example.test',
    ]);

    expect(Artisan::call('axispay:mail-test', ['email' => 'ops@example.test']))->toBe(0);

    $output = Artisan::output();
    expect($output)
        ->toContain('mail.example.test')
        ->toContain('465')
        ->toContain('smtps')
        ->toContain('(set, hidden)');
    expect(str_contains($output, 'super-secret-password'))->toBeFalse();

    Mail::assertSent(MailTestMessage::class, static fn (MailTestMessage $mail): bool => $mail->hasTo('ops@example.test'));
    Mail::assertNothingQueued();
});

it('queues the test e-mail with --queue', function (): void {
    Mail::fake();

    expect(Artisan::call('axispay:mail-test', ['email' => 'ops@example.test', '--queue' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Queued');

    Mail::assertQueued(MailTestMessage::class);
    Mail::assertNothingSent();
});

it('succeeds with the log mailer', function (): void {
    config(['mail.default' => 'log']);

    expect(Artisan::call('axispay:mail-test', ['email' => 'ops@example.test']))->toBe(0);
    expect(Artisan::output())->toContain('"log" mailer');
});

it('prints the transport error and exits 1 when the SMTP server is unreachable', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.scheme' => null,
        'mail.mailers.smtp.timeout' => 2,
    ]);

    expect(Artisan::call('axispay:mail-test', ['email' => 'ops@example.test']))->toBe(1);
    expect(Artisan::output())->toContain('Sending failed')->toContain('127.0.0.1');
});

it('rejects an invalid recipient', function (): void {
    expect(Artisan::call('axispay:mail-test', ['email' => 'not-an-email']))->toBe(1);
});
