<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Console;

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Plan 23.2: re-encrypts every stored merchant credential with the current
 * GATEWAY_CREDENTIALS_KEY version. Rotation:
 *
 *  1. move the old key to GATEWAY_CREDENTIALS_PREVIOUS_KEYS ("1:base64:..."),
 *     set the new key and bump GATEWAY_CREDENTIALS_KEY_VERSION;
 *  2. run this command;
 *  3. remove the old key once it reports nothing left on old versions.
 *
 * Each row is rewritten in its own tenant context, under a row lock.
 */
final class RotateGatewayCredentialsKeyCommand extends Command
{
    protected $signature = 'axispay:rotate-gateway-credentials-key';

    protected $description = 'Re-encrypt stored gateway credentials with the current GATEWAY_CREDENTIALS_KEY version.';

    public function handle(GatewayConnectionResolver $resolver, GatewayCredentialsEncrypter $encrypter, TenantContext $context): int
    {
        $current = $encrypter->currentVersion();
        $rotated = 0;

        foreach ($resolver->withCredentialsOnOldKeys($current) as $row) {
            $context->runAsTenant($row->tenant_id, $row->livemode, function () use ($row, $encrypter, $current, &$rotated): void {
                DB::transaction(function () use ($row, $encrypter, $current, &$rotated): void {
                    $connection = GatewayConnection::query()->lockForUpdate()->find($row->id);

                    if ($connection === null || $connection->credentials_key_version === $current) {
                        return;
                    }

                    $version = $connection->credentials_key_version;
                    $values = [];

                    foreach (['credentials_secret', 'provider_webhook_secret'] as $column) {
                        $ciphertext = $connection->getAttribute($column);

                        if (is_string($ciphertext)) {
                            $values[$column] = $encrypter->encrypt($encrypter->decrypt($ciphertext, $version))->ciphertext;
                        }
                    }

                    $connection->forceFill([...$values, 'credentials_key_version' => $current])->save();
                    $rotated++;
                });
            });
        }

        $this->components->info("Re-encrypted {$rotated} connection(s) with key version {$current}.");

        return self::SUCCESS;
    }
}
