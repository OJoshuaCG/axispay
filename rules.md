# CLAUDE.md — Reglas del proyecto PayLink

Plataforma SaaS multi-tenant de Payment Links sobre Stripe Connect (Laravel + MariaDB).
**El plan completo y fuente de verdad está en `docs/plans/master.md`. Léelo antes de empezar cualquier fase.**

## Cómo trabajar
- Trabaja por fases (`docs/plans/master.md`, sección 27). No adelantes fases.
- Las decisiones `DECIDIDO` (sección 3) no se cambian sin consultar. Si ves un problema, detente, explica y propón.
- Ante ambigüedad, pregunta antes de asumir. Registra las decisiones nuevas en `docs/adr/NNNN-titulo.md`.
- Mantén actualizados `docs/api/openapi.yaml`, `docs/adr/` y `CHANGELOG.md`.
- Una fase termina solo con sus criterios de aceptación cumplidos y CI en verde.

## Reglas no negociables
1. **Dinero:** nunca `float`/`double`. Montos en `BIGINT` (unidades mínimas) + moneda `CHAR(3)`. La API recibe y devuelve montos como string decimal. Usa `brick/money`. Redondeo `HALF_UP`, una sola vez, al final. Tipos de cambio en `DECIMAL(18,6)`.
2. **Multi-tenant:** todo modelo con `tenant_id` usa el trait `BelongsToTenant` (fail-closed). Prohibido `DB::table()`/SQL crudo sobre tablas de tenant y `withoutGlobalScope(s)` fuera de la lista blanca. Las FKs entre tablas de tenant incluyen `tenant_id`. Los jobs de tenant implementan `TenantAware`.
3. **Aislamiento probado:** cada endpoint o recurso nuevo lleva su prueba de aislamiento (acceso cruzado → `404`).
4. **Test/live:** los datos y las llaves de ambos modos nunca se mezclan (`livemode` en todas las tablas de negocio).
5. **Stripe:** toda llamada que crea o modifica recursos lleva `idempotency_key`; en reintentos, la misma key. Direct charges con `Stripe-Account`. Nunca exponer IDs de Stripe en la API pública. El dominio no conoce Stripe: todo pasa por el puerto `PaymentGateway`.
5b. **Métodos de conexión** (`platform_onboarding`, `oauth`, `api_key`): toda llamada a Stripe obtiene su contexto de `StripeClientFactory`; nadie construye un `StripeClient` por su cuenta. Con `api_key`: solo restricted keys (`rk_`), **nunca** `sk_`; cifradas con `GATEWAY_CREDENTIALS_KEY` (no `APP_KEY`); jamás en logs, excepciones ni payloads de jobs (el job recibe el `connection_id`).
6. **Webhooks entrantes:** verificar firma sobre el cuerpo crudo → guardar con `provider_event_id` único → responder 200 → procesar en job → re-consultar el objeto en Stripe → aplicar vía Actions.
7. **Webhooks salientes:** outbox en la misma transacción, entrega por cola, firma HMAC (Standard Webhooks), protección SSRF en registro y entrega, sin seguir redirecciones.
7b. **Validación previa al cobro:** es un callback síncrono, no un webhook. Timeout total de 5 s, política `fail_closed`/`fail_open` del tenant, mismas reglas SSRF, y **nunca** se llama mientras se mantiene un bloqueo de fila o una transacción abierta; después de la llamada, se re-bloquea y se re-verifica el estado.
8. **Transiciones de estado** solo mediante las máquinas de estado, dentro de una transacción con `lockForUpdate()`.
9. **Un solo PaymentIntent activo por link** (restricción única en BD + lógica).
10. **Seguridad:** sin secretos ni PII en logs; secretos de webhooks, 2FA y PII cifrados en BD; permisos (nunca nombres de rol) en las Policies; re-autenticación en acciones sensibles.
11. **MariaDB:** `utf8mb4` / `utf8mb4_uca1400_ai_ci` por defecto; `ascii_bin` para ULIDs, tokens, hashes, idempotency keys e IDs de Stripe; `DATETIME(6)` en UTC; `sql_mode` estricto.
12. **Sin lógica de negocio** en controllers ni en recursos de Filament: usa Actions de un solo propósito con DTOs.

## Convenciones de código
- PHP 8.3+ con `declare(strict_types=1);`. Enums para estados y modos.
- Estructura: `app/Modules/<Modulo>/{Models,Actions,Data,Enums,Events,Jobs,Http,Policies,Services,Exceptions}`.
- IDs: ULID como PK; en la API con prefijo (`plink_`, `pay_`, `re_`, `evt_`).
- API keys: `plk_test_...` / `plk_live_...`, guardadas como hash SHA-256.
- Pruebas con Pest; análisis con Larastan; formato con Pint. Todo debe pasar en CI con la misma versión de MariaDB que producción.

## Comandos habituales
- Pruebas: `php artisan test` (o `./vendor/bin/pest`)
- Análisis: `./vendor/bin/phpstan analyse`
- Formato: `./vendor/bin/pint`
- Webhooks locales de Stripe (Connect): `stripe listen --forward-connect-to localhost/webhooks/stripe/connect/test`
- Webhooks locales (método `api_key`): `stripe listen --forward-to localhost/webhooks/stripe/direct/{connection_id}` con la cuenta del comercio de prueba