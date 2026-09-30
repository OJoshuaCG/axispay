<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Http\CheckoutResponses;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
use App\Modules\Checkout\Services\CheckoutLocale;
use App\Modules\Checkout\Services\CheckoutMerchantLogo;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Services\LegalMarkdown;
use App\Modules\Legal\Services\PlatformLegalDocuments;
use App\Modules\Legal\Services\TenantLegalDocuments;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Legal pages of the pay host (ADR-0056). Presentation only.
 *
 *  - GET /l/{token}/legal/{kind}: the merchant's privacy notice or terms, the
 *    page behind the checkout's dialog (what a payer without JavaScript, or
 *    in a new tab, sees). The link comes from its token through the
 *    tenant-safe resolver, so it only ever shows that link's merchant; an
 *    invalid token or a document that is not set answers the checkout's
 *    uniform 404.
 *  - GET /legal: the platform's privacy notice and terms (anchors #privacy
 *    and #terms), linked from the "Powered by" line of every payment page;
 *    404 while neither is published.
 */
final readonly class CheckoutLegalController
{
    public function __construct(
        private CheckoutLinkResolver $resolver,
        private CheckoutResponses $responses,
        private TenantLegalDocuments $documents,
        private TenantAccess $access,
        private LegalMarkdown $markdown,
        private CheckoutMerchantLogo $merchantLogo,
    ) {}

    public function tenant(Request $request, string $token, string $kind): Response|JsonResponse
    {
        $link = $this->resolver->resolve($token);
        $kind = LegalDocumentKind::tryFrom($kind);

        if ($link === null || $kind === null) {
            return $this->responses->notFound();
        }

        $document = $this->documents->find($link->tenant_id, $kind);

        if ($document === null) {
            return $this->responses->notFound();
        }

        CheckoutLocale::apply($request, $link->locale);

        return new Response(view('checkout.legal-document', [
            'merchant' => $this->access->displayName($link->tenant_id),
            'merchantLogo' => $this->merchantLogo->for($link),
            'supportEmail' => $this->access->supportEmail($link->tenant_id),
            'token' => $link->public_token,
            'document' => $document,
            'html' => $document->isText() ? $this->markdown->render((string) $document->body, 2) : null,
        ])->render());
    }

    public function platform(PlatformLegalDocuments $platform): Response|JsonResponse
    {
        $documents = $platform->all();

        if ($documents === []) {
            return $this->responses->notFound();
        }

        $html = [];

        foreach ($documents as $key => $document) {
            if ($document->isText()) {
                $html[$key] = $this->markdown->render((string) $document->body, 3);
            }
        }

        return new Response(view('checkout.platform-legal', ['documents' => $documents, 'html' => $html])->render());
    }
}
