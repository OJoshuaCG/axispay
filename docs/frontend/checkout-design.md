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

## Decisions (record in the Phase 4 ADR)
- Payer-facing Spanish uses **tú** (plan copy, Stripe iframe copy and Mexican checkout norm); panels keep usted. Add an i18n.md row.
- **No theme toggle** on checkout; follows OS (`prefers-color-scheme`); do NOT include the panel pre-paint script; leave data-theme unset. Phase 8 `background_style` may override later.
- **No card fill in dark**: everything on `bg-page`; from 768px a hairline card (`border border-line rounded-xl`). (primary fill on raised in dark = 2.94:1 fails; on page = 3.32:1 passes.)
- Pay button NOT sticky on mobile (SC 2.4.11, iOS keyboard).
- No fake logo until Phase 8; merchant name as text (never truncated, `break-words`).
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
