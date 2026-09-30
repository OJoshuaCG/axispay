<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Reads what went wrong in an HTTP call to a merchant from the cURL error
 * the client reports (shared by WebhookSender and PrePaymentValidationClient).
 *
 * @internal
 */
final class CurlFailure
{
    /** cURL errors of the TLS handshake and certificate checks. */
    private const array TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    private function __construct() {}

    /** Whether the exception is a transport failure of the HTTP client (not a bug of ours). */
    public static function isTransport(Throwable $e): bool
    {
        return $e instanceof ConnectionException || $e instanceof GuzzleConnectException || $e instanceof GuzzleRequestException;
    }

    public static function errno(Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/cURL error (\d+)/', $current->getMessage(), $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }

    public static function isTimeout(Throwable $e): bool
    {
        return self::errno($e) === 28;
    }

    public static function isTls(Throwable $e): bool
    {
        return in_array(self::errno($e), self::TLS_ERRNOS, true);
    }

    public static function isDns(Throwable $e): bool
    {
        return self::errno($e) === 6;
    }

    /**
     * The connection could not be opened, so nothing of the request left
     * (plan 15.8.5): the host did not resolve, refused or did not answer the
     * connection within the connection timeout. A timeout AFTER connecting
     * (the merchant may have processed the request) is not one of them, and
     * neither is a TLS failure (retrying does not fix a certificate).
     */
    public static function failedBeforeSending(Throwable $e): bool
    {
        $errno = self::errno($e);

        if ($errno === 6 || $errno === 7) {
            return true;
        }

        return $errno === 28 && preg_match('/(connection timed out|failed to connect|resolving timed out)/i', $e->getMessage()) === 1;
    }
}
