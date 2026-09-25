<?php

declare(strict_types=1);

namespace App\Modules\Shared\Console;

use App\Modules\Shared\Mail\MailTestMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a small test e-mail to check the outgoing mail configuration in any
 * environment, production included (ADR-0043). Synchronous by default, so a
 * transport error is shown right here; `--queue` goes through the queue like
 * the application's notifications, to also check the worker. Prints the
 * mailer, host, port, scheme and sender, never the password.
 */
final class MailTestCommand extends Command
{
    protected $signature = 'axispay:mail-test
        {email : Recipient address}
        {--queue : Queue the message (like invitations) instead of sending it now}';

    protected $description = 'Send a test e-mail through the configured mailer and show the mail settings (no secrets)';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('The recipient is not a valid e-mail address.');

            return self::FAILURE;
        }

        $mailer = config()->string('mail.default');
        $this->table(['Setting', 'Value'], $this->settings($mailer));

        $message = new MailTestMessage(now('UTC')->format('Y-m-d H:i:s'));

        try {
            if ($this->option('queue') === true) {
                Mail::mailer($mailer)->to($email)->queue($message);
                $connection = config()->string('queue.default');
                $this->info("Queued on the \"{$connection}\" queue connection. A queue worker must be running to send it; check failed jobs with `php artisan queue:failed`.");

                return self::SUCCESS;
            }

            Mail::mailer($mailer)->to($email)->send($message);
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e::class);
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->info($mailer === 'log'
            ? 'Handed to the "log" mailer: the message was written to the log at debug level (visible only with LOG_LEVEL=debug), not delivered.'
            : "Accepted by the \"{$mailer}\" mailer. If it does not arrive, check the spam folder and the sender domain's SPF/DKIM records.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{string, string}>
     */
    private function settings(string $mailer): array
    {
        $config = config('mail.mailers.'.$mailer);
        $config = is_array($config) ? $config : [];
        $value = static fn (string $key): string => isset($config[$key]) && is_scalar($config[$key]) && (string) $config[$key] !== '' ? (string) $config[$key] : '(not set)';

        $rows = [
            ['Mailer', $mailer],
            ['Transport', $value('transport')],
        ];

        if (($config['transport'] ?? null) === 'smtp') {
            $rows[] = ['Host', $value('host')];
            $rows[] = ['Port', $value('port')];
            // Laravel derives the scheme from the port when MAIL_SCHEME is empty.
            $rows[] = ['Scheme', $value('scheme') !== '(not set)'
                ? $value('scheme')
                : '(not set: '.($value('port') === '465' ? 'smtps' : 'smtp').', derived from the port)'];
            $rows[] = ['Username', $value('username')];
            $rows[] = ['Password', filled($config['password'] ?? null) ? '(set, hidden)' : '(not set)'];

            if (filled($config['url'] ?? null)) {
                $rows[] = ['MAIL_URL', '(set, hidden; it overrides host, port and credentials)'];
            }
        }

        $from = config('mail.from.address');
        $name = config('mail.from.name');
        $rows[] = ['From address', is_string($from) && $from !== '' ? $from : '(not set)'];
        $rows[] = ['From name', is_string($name) && $name !== '' ? $name : '(not set)'];

        return $rows;
    }
}
