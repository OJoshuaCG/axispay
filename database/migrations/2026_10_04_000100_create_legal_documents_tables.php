<?php

declare(strict_types=1);

use App\Modules\Shared\Ids\Ulid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal texts (ADR-0056): the merchant's privacy notice and terms and
 * conditions, and the platform's own two. Each document is either a text
 * (simple Markdown, `body`) or a link to an external page (`url`); a document
 * that is not set has no row.
 *
 * No `livemode`: a legal text is the merchant's public identity, like its
 * display name, the same for test and live payers; it holds no test or live
 * business data (rules.md rule 4 covers business records).
 *
 * The tenants' existing `privacy_notice_url` values move here as link
 * documents, and the column is dropped so there is one source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_legal_documents', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->asciiString('kind', 16);
            $table->asciiString('format', 8);
            $table->mediumText('body')->nullable();
            $table->string('url', 2048)->nullable();
            $table->foreignUlidAscii('updated_by_user_id')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'kind'], 'uq_tenant_legal_documents_kind');
            $table->unique(['tenant_id', 'id'], 'uq_tenant_legal_documents_tenant_id');
            $table->foreign('tenant_id', 'fk_tenant_legal_documents_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'updated_by_user_id'], 'fk_tenant_legal_documents_updated_by')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('platform_legal_documents', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->asciiString('kind', 16)->unique('uq_platform_legal_documents_kind');
            $table->asciiString('format', 8);
            $table->mediumText('body')->nullable();
            $table->string('url', 2048)->nullable();
            $table->ulidAscii('updated_by_platform_admin_id')->nullable();
            $table->datetimes(6);
        });

        $now = now()->utc()->format('Y-m-d H:i:s.u');

        DB::table('tenants')->select(['id', 'privacy_notice_url'])->whereNotNull('privacy_notice_url')->orderBy('id')
            ->chunkById(500, static function ($tenants) use ($now): void {
                $rows = [];

                foreach ($tenants as $tenant) {
                    $url = is_string($tenant->privacy_notice_url) ? trim($tenant->privacy_notice_url) : '';

                    if ($url !== '') {
                        $rows[] = ['id' => Ulid::generate(), 'tenant_id' => $tenant->id, 'kind' => 'privacy', 'format' => 'url', 'body' => null, 'url' => $url, 'created_at' => $now, 'updated_at' => $now];
                    }
                }

                if ($rows !== []) {
                    DB::table('tenant_legal_documents')->insert($rows);
                }
            });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('privacy_notice_url');
        });
    }

    /**
     * Back to the single URL column: link privacy notices return to it; texts
     * and terms have no place there and are lost.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('privacy_notice_url', 2048)->nullable()->after('support_email');
        });

        DB::table('tenant_legal_documents')->select(['id', 'tenant_id', 'url'])->where('kind', 'privacy')->where('format', 'url')->orderBy('id')
            ->chunkById(500, static function ($documents): void {
                foreach ($documents as $document) {
                    DB::table('tenants')->where('id', $document->tenant_id)->update(['privacy_notice_url' => $document->url]);
                }
            });

        Schema::dropIfExists('platform_legal_documents');
        Schema::dropIfExists('tenant_legal_documents');
    }
};
