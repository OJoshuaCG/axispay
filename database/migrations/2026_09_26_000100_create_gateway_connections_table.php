<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant connections to payment gateways (plan 7.4, ADR-004, ADR-0047). Every
 * column of the plan is created now, including the oauth and api_key ones,
 * so later phases need no migration.
 *
 * Uniqueness:
 *  - one non-disconnected connection per (tenant, provider, mode): unique on
 *    the generated `active_slot` (1 while not disconnected, NULL after);
 *  - a gateway account is linked to one live connection at a time: unique on
 *    the generated `active_provider_account_id` (ADR-0047 explains why the
 *    disconnected rows are left out; the application also refuses an account
 *    another tenant ever linked);
 *  - the fingerprint of an api_key secret is unique while stored.
 *
 * Credentials (`credentials_secret`, `provider_webhook_secret`) hold
 * ciphertext of GatewayCredentialsEncrypter (dedicated key, never APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_connections', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->asciiString('provider', 32);
            $table->asciiString('connection_method', 32);
            $table->asciiString('provider_account_id', 255)->nullable();
            $table->asciiChar('country', 2)->nullable();
            $table->asciiChar('default_currency', 3)->nullable();
            $table->asciiString('status', 32);
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->json('requirements')->nullable();

            // api_key only (NULL for the other methods).
            $table->text('credentials_secret')->nullable();
            $table->asciiString('credentials_publishable', 255)->nullable();
            $table->asciiChar('credentials_fingerprint', 64)->nullable()->unique('uq_gateway_connections_fingerprint');
            $table->asciiString('credentials_last4', 4)->nullable();
            $table->unsignedSmallInteger('credentials_key_version')->nullable();
            $table->asciiString('provider_webhook_endpoint_id', 255)->nullable();
            $table->text('provider_webhook_secret')->nullable();
            $table->json('validated_permissions')->nullable();
            $table->dateTime('last_health_check_at', 6)->nullable();
            $table->asciiString('last_health_check_status', 32)->nullable();

            // oauth only (Phase 4B). The access token is never stored.
            $table->asciiString('oauth_scope', 32)->nullable();

            $table->dateTime('risk_acknowledged_at', 6)->nullable();
            $table->foreignUlidAscii('risk_acknowledged_by_user_id')->nullable();
            $table->dateTime('connected_at', 6)->nullable();
            $table->dateTime('disconnected_at', 6)->nullable();
            $table->asciiString('disconnect_reason', 64)->nullable();
            $table->dateTime('last_synced_at', 6)->nullable();
            $table->datetimes(6);

            $table->unsignedTinyInteger('active_slot')->nullable()
                ->storedAs("IF(`status` <> 'disconnected', 1, NULL)");
            $table->string('active_provider_account_id', 255)->charset('ascii')->collation('ascii_bin')->nullable()
                ->storedAs("IF(`status` <> 'disconnected', `provider_account_id`, NULL)");

            $table->unique(['tenant_id', 'id'], 'uq_gateway_connections_tenant_id');
            $table->unique(['tenant_id', 'provider', 'livemode', 'active_slot'], 'uq_gateway_connections_active');
            $table->unique(['provider', 'active_provider_account_id', 'livemode'], 'uq_gateway_connections_account');
            $table->index(['provider', 'provider_account_id', 'livemode'], 'ix_gateway_connections_account');
            $table->index(['connection_method', 'status'], 'ix_gateway_connections_method_status');

            $table->foreign('tenant_id', 'fk_gateway_connections_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'risk_acknowledged_by_user_id'], 'fk_gateway_connections_risk_user')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_connections');
    }
};
