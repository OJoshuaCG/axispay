# Checkout (Phase 4) design spec — ui-ux-designer, orchestrator-approved 2026-09-27

The approved design spec of the payment page, kept here as the reference
cited by [ADR-0051](../adr/0051-checkout-and-card-payments-phase-4.md). The
amendments made during Phase 4 are listed first; where they disagree with
the original text below, the amendments win.

## Amendments (Phase 4)

- **Stripe font weights:** two, 400 and 500. The Appearance's bold weight is 500 (`fontWeightBold: '500'`), so Stripe's card iframe downloads only the Mukta 400 and 500 files (the original text lists 400/500/600).
- **Turnstile:** required from the first payment try after a decline on the link or in the payer's session (the original text says "after the 2nd failure"). Configurable; pending the owner's confirmation (ADR-0051).
- **Alerts:** the error and warning alerts of the form sit right before the Payment Element, below the payer fields; they use `role="group"` (focus announces them once) instead of `role="alert"`.
- **Phone field:** the country select shows "{country} (+{code})" in full. It stacks above the number on phones and sits beside it (`sm:w-64`) from 640px.
- **Preconnect:** to `https://api.stripe.com` (Stripe.js itself is fetched right away and deferred), not to `js.stripe.com`.
- **Skeleton:** the reserved height exists only while the skeleton is shown; the sandbox stub has its own, shorter skeleton.
- **Page title:** "Pagar · {merchant}" / "Pay · {merchant}" while the link can be paid.

Tokens only; one new layout token `--container-checkout` (60rem) in resources/css/theme.css. No new fonts.

## Amendments (ADR-0056, 2026-09-30)

- **Legal links:** below the amount, in every state, "Aviso de privacidad · Términos" / "Privacy notice · Terms" for whichever documents the merchant published (`<x-checkout.legal-links>`, text-sm fg-secondary, 44px targets, `·` separators hidden from screen readers, inside a `<nav>` labelled "Documentos legales" / "Legal documents").
- **Text documents** open in a native modal `<dialog>` (max-w-narrow, hairline border, rounded-xl, bg-page, scrim `neutral-900/60`): heading h2 with the full name ("Términos y condiciones"), an icon-only close button focused on opening, the text in a scrollable, focusable region (`legal-prose`). Escape, the close button or a click on the backdrop close it; focus returns to the link. Without JavaScript the link opens the document's own page, `/l/{token}/legal/{kind}` (merchant header, "Volver al pago" / "Back to the payment", h1, the text), which answers the uniform 404 when the token or the document does not exist.
- **Link documents** open in a new tab, `rel="noopener noreferrer"`, with an sr-only "(se abre en una pestaña nueva)" / "(opens in a new tab)".
- **Payer fields:** the line under them links the merchant's notice the same way; the fields are collected with a text or a link notice, never without one.
- **Footer:** the merchant's privacy link moves to the summary (no longer repeated in the footer). "Powered by" links to the platform's `/legal` page while the platform published a privacy notice or terms; plain text otherwise.
- **`/legal`** (pay host): language switcher, h1 "Información legal" / "Legal information", an intro naming the platform, anchors to `#privacy` and `#terms` when both exist, one section per document (h2; a text's headings from h3; a link document shows a link out), the platform footer. 404 while neither exists. No merchant information.
- No CSP change: the dialogs are server-rendered and opened by the checkout bundle (`resources/js/checkout/legal-dialog.js`).
- **Merchant logo** (ADR-0056 part B; `<x-checkout.merchant-header>`, also on `/l/{token}/legal/{kind}`): the language switcher on its own row at the end, then the logo centered, `alt` = merchant name (no visible name beside it), `width`/`height` set, `h-auto w-auto max-h-20 max-w-56 object-contain sm:max-h-24 sm:max-w-72 lg:max-w-80`. With a dark variant both images are rendered, swapped with `dark:hidden` / `hidden dark:block`; without one, the light logo gets `rounded-lg p-inset-sm bg-logo-plate` (`logo-plate`: transparent in light, neutral-50 in dark). No logo: the merchant name as text, as before. Served same-origin from `/l/{token}/logo/{light|dark}/{version}.png` (no CSP change: `img-src 'self'`).

## Amendments (ADR-0056 part C, 2026-09-30): layout, theme, footer

These replace "No theme toggle", "No card fill in dark", the Layout section, the footer line and the Keyboard order below, and the dialog and header details of the amendments above, where they disagree.

- **Canvas and cards:** the body is `bg-canvas` (`<html>` stays `page`); the content sits in `<x-checkout.card>`: `rounded-xl border border-line bg-page p-inset-md shadow-sm sm:p-inset-lg`. Light: white cards with a hairline and a small shadow on the near-white canvas. Dark: canvas and page are the same value, so the cards are flat and the border does the work. Never `x-card` (raised in dark puts the Pay fill at 2.94:1). The Stripe probe still reads `page`, so the card form's iframe matches the card. Shell: main `py-stack-md md:py-stack-lg`.
- **Layout:** `max-w-narrow` (640px) stacked up to 1023px; from 1024px `max-w-checkout` (960px, the existing `--container-checkout`) with `lg:grid-cols-5 lg:items-start`: the details card `col-span-2` on the left, the form card `col-span-3` on the right, gap `stack-lg`. No `sticky` (a summary taller than the viewport would be unreachable). 320px: 16px gutter, 16px card padding, 254px inside.
- **Header:** row 1 `<x-site-controls>` at the end (language + theme, the theme icon-only at every width; labels stay the accessible names and tooltips); row 2 the logo centered (part B classes) or the merchant name `text-center text-2xl font-semibold`. Pages without a merchant (404, 429, error pages, `/legal`) show row 1 only.
- **Left card (order summary):** "Payment to {merchant}" (`text-sm font-medium fg-secondary`, no heading); the description (`text-base`); under a hairline, a visible `<dt>` "Total to pay" (`text-sm fg-secondary`) and the total `<x-amount split>` (`text-3xl sm:text-4xl` semibold, the code `text-xl fg-secondary`), then the FX legend (`data-fx-legend`, `text-sm fg-secondary`, only when a Mexican card may be converted; ADR-0063); the expiry (< 72h) with a clock icon; the legal links under a hairline.
- **Total wrapping:** `split` renders the number and the ISO code as two pieces; the number never breaks, the code wraps below it when the card is narrow ("1,500,000.00 MXN" at 3xl is ≈269px against 254px at 320px). The text read is unchanged.
- **Right card:** the live region and sr-only h1 unchanged; the payer fields (legend `text-base font-semibold`); then, under a hairline only when there are payer fields, the alerts, the card form (its label `text-base font-semibold`) and Turnstile; then Pay (`lg w-full`, the only primary fill on the page) and the trust line.
- **States:** Active and Processing are two cards (Processing shows the status panel in the right card; the amount stays on the left); a rejection swaps its panel into the right card. Paid, already paid, expired, canceled, blocked: one centered `max-w-narrow` card, the status panel first, then under a hairline the summary without its own card (`bare`, same amount rules) with the legal links. 404, 429 and the error pages: one card with the status; the header's controls only; the footer's platform tier only.
- **Theme:** a light / dark / system choice (`<x-theme-toggle>` inside `<x-site-controls>`), **light by default** like every other page (ADR-0044); "System" follows the OS. Saved in `localStorage['theme']` on the pay origin; applied before first paint by the shared `<x-theme-prepaint>` (nonce'd), which also narrows `<meta name="color-scheme">` to the chosen scheme. Stripe's Appearance (`theme`, colors) and Turnstile follow the theme on screen: `theme:change` from `theme.js`, and OS changes only while "System" is in effect.
- **Footer:** two tiers, no rule above: the merchant's help line "Questions about your payment? Email {email}" / "¿Dudas sobre tu pago? Escribe a {email}" (`text-sm`, only with a support e-mail), then `text-xs` "Powered by {platform}" · "Processed by Stripe" (the `·` in `fg-muted`, hidden from screen readers). "Powered by" links to `/legal` in a new tab (sr-only "(opens in a new tab)") while the platform has a document. Two centered lines up to 1023px; one row from 1024px (help at the start, platform tier at the end).
- **Legal dialog:** a full-screen sheet below 640px (safe-area padding); from 640px `max-w-narrow`, at most 5/6 of the viewport height, `rounded-xl border shadow-xl`. Header: the merchant (small, fg-secondary) and the h2 title; a scrollable, focusable body (`legal-prose`); a footer with a secondary "Close" button (full width on phones). Focus goes to the h2 on opening (long content: the reader starts at the top, APG dialog pattern); Escape, Close and the backdrop close it, focus returns to the link. The page behind does not scroll (`html:has(dialog[open])` in base.css); it fades in unless reduced motion is asked for (`dialog-enter`).
- **Card-form skeleton:** shaped like Stripe's form: the number across; expiry and CVC side by side (`grid-cols-2 gap-stack-sm`); the country across (`bg-surface-alt rounded-md`, 16px labels, 44px fields, `min-h-60`).
- **Spacing:** only `stack-xs/sm/md/lg` on the page.
- **Keyboard order:** skip link → language → theme → legal links → payer fields → card form → Turnstile → Pay → footer help → platform link.

## Decisions (record in the Phase 4 ADR)
- Payer-facing Spanish uses **tú** (plan copy, Stripe iframe copy and Mexican checkout norm); panels keep usted. Add an i18n.md row.
- **No theme toggle** on checkout; follows OS (`prefers-color-scheme`); do NOT include the panel pre-paint script; leave data-theme unset. Phase 8 `background_style` may override later.
- **No card fill in dark**: everything on `bg-page`; from 768px a hairline card (`border border-line rounded-xl`). (primary fill on raised in dark = 2.94:1 fails; on page = 3.32:1 passes.)
- Pay button NOT sticky on mobile (SC 2.4.11, iOS keyboard).
- No fake logo; without the merchant's logo (ADR-0056 part B), the merchant name as text (never truncated, `break-words`).
- CSP: add `https://*.js.stripe.com` to script-src and frame-src (Stripe docs).

## Layout
Shell `min-h-dvh px-gutter pt-safe-top pb-safe-bottom`; DOM order header → summary → form → footer.
- 320–639: single column on page. Header: merchant name (text-lg semibold) left + `<x-language-switcher>` right (wraps). Then summary, payer fields, Payment Element, Pay (`w-full size lg`), trust line, footer.
- 768: centered `max-w-narrow`, `border border-line rounded-xl p-inset-lg`.
- 1024+: `max-w-checkout`, `lg:grid-cols-5`: summary `col-span-2` LEFT, `lg:sticky lg:top-stack-xl`; form `col-span-3`; header spans both.
Order summary `<dl>`: "Pago a {merchant}" (fg-secondary); description (escaped, full); sr-only dt "Total a pagar" + `<x-amount :signed="false" class="text-3xl font-semibold">`; FX slot (Phase 6); expiry only if < 72h "Vence el :date" in tenant TZ with abbreviation in `<time>`.
Logo slot (Phase 8) `h-10 max-w-40 object-contain`, alt = merchant name, name still shown.
Payer fields before card; labels above; "(opcional)". Email `type=email autocomplete=email`; name `autocomplete=name`; phone new `<x-phone-input>` (native country select default +52, `type=tel autocomplete=tel-national`). When payer data collected, under fields: "{merchant} recibirá estos datos. Consulta su [aviso de privacidad]."
Trust line under button: lock icon + "Pago seguro: los datos de tu tarjeta van cifrados directamente a Stripe." / "Secure payment: your card details go encrypted straight to Stripe." (text-sm fg-secondary).
Footer (every state, ADR-0038) text-sm fg-secondary: "Con la tecnología de AxisPay"/"Powered by AxisPay" (platform brand per ADR-0053: logo, name or both as the superadmin set it, name alone without a logo; `components/checkout/platform-brand.blade.php`, logo `h-5 max-w-32`, dark variant swapped with `dark:`), "Procesado por Stripe"/"Processed by Stripe", privacy link when fields collected, mailto support email when exists; links min-h-touch.

## States & copy (ES tú / EN)
One page-level `<div id="checkout-live" role="status" class="sr-only">` announces each phase once (never per poll). Terminal state replaces the form with `<x-checkout.status-panel>` whose `<h1 tabindex="-1">` gets focus. `<title>` = "{state heading} · {merchant}".
- Button: "Pagar 1,500.00 MXN" / "Pay 1,500.00 MXN" (`checkout.pay_amount` with escaped amount). aria-disabled until Payment Element `ready`.
- Submitting: "Procesando pago…" / "Processing payment…" (inFlight flag; server idempotency still required).
- 3DS: "Esperando la confirmación de tu banco…" / "Waiting for your bank to confirm…".
- 3DS failed/closed: "No pudimos completar la verificación de tu banco. Intenta de nuevo o usa otra tarjeta." / "We couldn't complete your bank's verification. Try again or use another card." (error alert).
- Merchant validation (Phase 5 hook, driven by server `phase`): "Verificando tu pedido…" / "Checking your order…".
- Processing: "Tu pago se está procesando. No cierres esta página." / "Your payment is processing. Please keep this page open." (info panel; poll 3s up to 2 min).
- Processing timeout: "Aún no tenemos la confirmación final. No vuelvas a pagar: revisa este enlace más tarde." / "We don't have final confirmation yet. Don't pay again: check this link later." + secondary "Revisar de nuevo"/"Check again".
- Paid (this session): "Pago realizado" / "Payment complete" (success panel: amount, date, description; return_url → primary "Volver a {merchant}"/"Return to {merchant}", rel noopener noreferrer).
- Paid already/other tab: "Este cobro ya fue pagado" / "This payment has already been made" (description + date only).
- Declined: "La tarjeta fue rechazada. Intenta con otra o contacta a tu banco." / "Your card was declined. Try another card or contact your bank." (error alert above Payment Element, focused; form usable).
- Merchant rejected (Phase 5): heading "{merchant} no pudo aceptar este pago" / "{merchant} couldn't accept this payment"; body payer_message escaped in blockquote "Mensaje de {merchant}:" or fallback "Contacta a {merchant} para más información." / "Contact {merchant} for more information.".
- Authorization voided: "No se hizo ningún cargo. Tu banco puede mostrar un cargo pendiente por unos días; se liberará sin que hagas nada." / "You were not charged. Your bank may show a pending charge for a few days; it will be released automatically." (info).
- Turnstile: "Por seguridad, confirma que eres una persona antes de volver a intentar." / "For security, please confirm you're human before trying again.".
- Rate limited: "Por seguridad, pausamos los pagos por un momento. Intenta de nuevo en :minutes minutos." / "For security, payments are paused. Try again in :minutes minutes." (warning; button aria-disabled).
- Long block 24h: "Este enlace no acepta pagos por ahora. Contacta a {merchant}." / "This link isn't accepting payments right now. Contact {merchant}.".
- Expired: "Este enlace de pago expiró. Contacta a {merchant}." / "This payment link has expired. Contact {merchant}." (+ support email).
- Canceled: "Este enlace de pago ya no está disponible." / "This payment link is no longer available.".
- 404: "No encontramos este enlace. Revisa que la dirección esté completa." / "We couldn't find this link. Check that the address is complete." (no merchant info; platform footer; identical for any invalid token, same timing).
Turnstile: only after 2nd failure; between Payment Element and Pay; `appearance: 'always'`; `compact` below 340px container, else `flexible`; reserve height; pass theme + language; Pay stays enabled; without token, submit focuses widget.

## Components
Reuse: x-button, x-input, x-alert, x-amount, x-icon, x-language-switcher. Not x-theme-toggle, not x-card.
New: layouts/checkout (robots noindex, no theme pre-paint, nonce, footer), checkout/merchant-header, checkout/order-summary, checkout/status-panel (variant, icon in *-subtle circle, h1, body, actions; no illustrations), checkout/payment-element (container + skeleton + error slot), checkout/turnstile, checkout/footer, x-phone-input.
Errors: field errors inline via x-input; on submit focus first invalid; Stripe card errors inline (paymentElement.focus()); server errors in one x-alert above the Payment Element with tabindex=-1, focused programmatically; button resets after any error.
No layout shift: container `relative min-h-60`; skeleton overlay of three `bg-surface-alt rounded-md` bars (label + 44px input), no shimmer, removed on `ready`. Reduced motion: spinners static.

## Stripe Appearance
Read colors from hidden probe elements with utility classes (getComputedStyle → hex), not from --color-* vars (lightningcss switch tokens). Re-run `elements.update({appearance})` on prefers-color-scheme change.
theme stripe/night; labels above; colorPrimary=primary (#1a54a9 / #1f63c7); colorBackground=page (#ffffff / #0d1017); colorText=fg; colorTextSecondary=fg-secondary; colorTextPlaceholder=fg-muted; colorDanger=error; iconColor=fg-secondary; fontFamily 'Mukta', system-ui, sans-serif; fontSizeBase 16px; spacingUnit 4px; borderRadius 8px; weights 400/500/600; rules .Input border 1px solid line-strong, padding 9px 12px; .Input:focus outline 2px solid focus-ring offset 2px no box-shadow; .Input--invalid border error. locale es-419 / en. billingDetails 'never' for fields we collect (passed in createConfirmationToken). wallets off. Fonts via elements({fonts:[{family:'Mukta', src:url(<Vite asset woff2>), weight}]}) — needs CORS on our woff2 (verify in spike; fallback system-ui, never a CDN).
Merchant color (Phase 8, design only): replaces primary/hover/active/on-primary only (Pay button, Stripe colorPrimary, logo tile); emitted as nonced <style> on :root; accepted only if label ≥4.5:1 on each fill state and fill vs page ≥3:1 in both themes, else derive per theme or fall back.

## Accessibility
All pairs on page already measured (fg 19.03/18.04, fg-secondary 5.78/10.33, fg-muted 4.96/6.57, error 6.47/5.91, label on primary 7.28/5.73, primary vs page 7.28/3.32, line-strong 3.47/3.83, focus 7.28/5.79). Avoid primary fill on raised in dark (2.94) and error on raised in dark (4.52). Keyboard: skip link → language → payer fields → Payment Element → Turnstile → Pay → footer links; Enter submits. Targets ≥44px incl footer links. html lang = locale; x-amount lang; language switch reloads (acceptable, above form). Visible "1,500.00 MXN" is the accessible text + sr-only "Total a pagar"; no aria-label override on button.

## Performance & CSP
Mukta 400/600 preloaded; preload Geist Mono 600 on checkout only; preconnect https://js.stripe.com; Stripe.js from js.stripe.com with nonce (not bundled); Turnstile api.js only when challenge needed, injected with nonce. No analytics/third-party fonts/maps.
CSP: script-src 'self' 'nonce-…' https://js.stripe.com https://*.js.stripe.com https://challenges.cloudflare.com; frame-src https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com https://challenges.cloudflare.com; connect-src 'self' https://api.stripe.com; font-src 'self'; img-src 'self' data: <logo origin>; style-src 'self' 'nonce-…'; frame-ancestors 'none'. return_url links rel="noopener noreferrer".
