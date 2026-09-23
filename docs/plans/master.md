# Plan maestro — Plataforma de Payment Links multi-tenant sobre Stripe Connect

> **Documento fuente de verdad para el desarrollo.**
> Está escrito para que Claude Code (u otro desarrollador) lo lea completo antes de escribir código, entienda *qué* se construye, *por qué* se tomó cada decisión, *qué alternativas se descartaron* y *qué queda fuera de alcance*.
>
> **Nombre de trabajo del producto:** `PayLink` (provisional). En código se usa el prefijo/namespace `paylink` y el prefijo de llaves `plk_`. Cambiar el nombre comercial no debe requerir cambios en el dominio.
>
> **Fecha de redacción:** septiembre 2026.
>
> **Historial de revisiones:**
> - **v1.0:** versión inicial.
> - **v1.1:** ADR-004 cambia a tres métodos de conexión (`platform_onboarding`, `oauth`, `api_key`); nuevo ADR-024 (validación previa al cobro); nuevas secciones 12.3.1–12.3.4, 12.4.1 y 15.8; tablas `validation_endpoints` y `validation_calls`; columnas nuevas en `gateway_connections` y `payment_attempts`; endpoint de webhooks direct por conexión; nueva fase 4B; casos críticos 18–24.

---

## Índice

0. Cómo usar este documento (instrucciones para Claude Code)
1. Glosario
2. Visión del producto y alcance
3. Registro de decisiones (ADRs): opciones evaluadas y decisión tomada
4. Arquitectura general
5. Stack tecnológico
6. Multi-tenancy y aislamiento de datos (MariaDB sin RLS)
7. Modelo de datos
8. Representación del dinero y redondeo
9. Máquinas de estado
10. Contrato de la API pública v1
11. Página de pago pública (checkout)
12. Integración con Stripe Connect
13. Conversión de moneda (FX) y Banxico
14. Webhooks entrantes de Stripe
15. Webhooks salientes hacia clientes y validación previa al cobro
16. Reembolsos y disputas
17. Usuarios, roles y permisos (RBAC)
18. Branding / white label
19. Campos configurables del pagador
20. Métricas y reportes
21. Cobro de la plataforma a sus clientes (externo), plan tarifario y estado del tenant
22. Notificaciones por correo
23. Seguridad
24. Observabilidad y operación
25. Infraestructura y despliegue (servidor propio)
26. Estrategia de pruebas
27. Plan de implementación por fases (con criterios de aceptación)
28. Fuera de alcance / fases futuras
29. Preguntas abiertas y decisiones pendientes de confirmar
30. Checklist de salida a producción

---

## 0. Cómo usar este documento (instrucciones para Claude Code)

1. **Lee el documento completo antes de empezar.** Las secciones se referencian entre sí; las decisiones de la sección 3 condicionan todo lo demás.
2. **Las decisiones marcadas como `DECIDIDO` no se reabren** sin consultar al responsable del proyecto. Si encuentras un motivo técnico fuerte para cambiar una, detente, explica el motivo y propone la alternativa; no la cambies por tu cuenta.
3. **Las decisiones marcadas como `PROPUESTO` o `PENDIENTE`** (sección 29) requieren confirmación. Implementa la opción recomendada solo cuando la fase lo requiera y deja constancia en `docs/adr/`.
4. **Trabaja por fases (sección 27).** No adelantes fases. Cada fase termina con sus criterios de aceptación cumplidos, pruebas en verde y documentación actualizada.
5. **Reglas no negociables** (también en `CLAUDE.md`):
   - Nunca usar `float`/`double` para dinero ni para tipos de cambio.
   - Nunca consultar tablas de tenant sin contexto de tenant; nunca usar `DB::table()` ni SQL crudo sobre ellas; nunca `withoutGlobalScopes()` fuera del contexto de plataforma auditado.
   - Toda llamada a Stripe que crea o modifica recursos lleva `idempotency_key`.
   - Todo webhook entrante se verifica por firma antes de procesarse y se procesa de forma idempotente.
   - Todo webhook saliente se firma y se entrega vía outbox + cola, nunca de forma síncrona en un request.
   - Ningún dato sensible (API keys, secretos, PAN, PII) aparece en logs.
   - Cada endpoint nuevo incluye su prueba de aislamiento entre tenants.
6. **Si algo en este plan es ambiguo o contradictorio**, pregunta antes de asumir. Documenta la respuesta en el ADR correspondiente.
7. **Mantén actualizados:** `docs/adr/*.md` (una decisión por archivo), `docs/api/openapi.yaml` (contrato de la API) y el `CHANGELOG.md`.

---

## 1. Glosario

| Término | Significado en este sistema |
|---|---|
| **Plataforma** | Nuestro sistema (`PayLink`) y nuestra cuenta de plataforma en Stripe Connect. |
| **Operador / Superadmin** | Personal de nuestra empresa que administra la plataforma completa. |
| **Tenant / Cliente** | Empresa o comercio que contrata la plataforma para cobrar a sus propios clientes. |
| **Usuario del tenant** | Persona con acceso al panel del tenant (dueño, admin, finanzas, etc.). |
| **Integrador** | Sistema del tenant que consume nuestra API (su tienda en línea, ERP, etc.). |
| **Pagador** | Cliente final del tenant que abre el link y paga. No tiene cuenta en nuestra plataforma. |
| **Payment Link / Link** | Entidad propia de la plataforma: un cobro de monto y moneda fijos, accesible en una URL de nuestro dominio. **No es** un Stripe Payment Link. |
| **Intento de pago (Payment Attempt)** | Representación interna de un PaymentIntent de Stripe (o equivalente de otra pasarela) asociado a un link. |
| **Cotización FX (FX Quote)** | Snapshot inmutable de una conversión USD→MXN: tipo de cambio, fuente, markup, montos, vigencia. |
| **Cuenta conectada** | Cuenta de Stripe del tenant vinculada a la plataforma. Con `platform_onboarding` u `oauth` es una cuenta de Stripe Connect (`acct_...`); con `api_key` es la cuenta del comercio accedida con sus propias llaves. |
| **Método de conexión** | Forma en que el tenant vincula su cuenta de Stripe: `platform_onboarding`, `oauth` o `api_key` (ADR-004). |
| **Validación previa al cobro** | Callback síncrono al sistema del tenant, justo antes de confirmar un cobro, cuya respuesta (`approve`/`reject`) decide si se cobra (ADR-024). **No es un webhook.** |
| **Direct charge** | Cargo creado directamente en la cuenta conectada (header `Stripe-Account`). El tenant es el comercio de registro. |
| **Livemode** | Distinción test/producción, alineada con los modos test/live de Stripe. |
| **Outbox** | Tabla donde se registran eventos en la misma transacción que el cambio de estado, para entregarlos después de forma confiable. |
| **Unidades mínimas (minor units)** | Centavos. 150.50 MXN = `15050`. |
| **bps (basis points)** | Centésimas de punto porcentual. 0.5% = 50 bps; 1.5% = 150 bps. |

---

## 2. Visión del producto y alcance

### 2.1 Problema que resuelve

Comercios y empresas (con o sin tienda en línea) que **no tienen una pasarela de pago integrada**, o que no saben o no pueden generar enlaces de pago únicos, necesitan cobrar en línea de forma simple:

- **Vía API:** su sistema solicita un link con monto, moneda, descripción y metadata, y recibe la URL para enviarla al pagador.
- **Vía panel web:** usuarios del tenant crean links manualmente y consultan el estado de sus cobros.

La plataforma **solo cobra un monto en una moneda**. No sabe si el cobro corresponde a una suscripción, un pago parcial o una venta; esa lógica pertenece al tenant.

### 2.2 Propuesta de valor frente a alternativas

Stripe ya ofrece Payment Links y Checkout nativos. Nuestra diferenciación debe estar en:

1. **API unificada e independiente de la pasarela:** hoy Stripe; en el futuro Mercado Pago, Conekta, etc., sin que el integrador cambie su código.
2. **Links en nuestro dominio con white label** (logo, colores, nombre del comercio).
3. **Eventos más ricos que los de Stripe:** por ejemplo, `payment_link.opened`, con webhooks normalizados y firmados.
4. **Conversión automática USD→MXN** con tipo de cambio de Banxico, que resuelve la restricción de Stripe para tarjetas mexicanas en cuentas mexicanas.
5. **Panel multi-usuario con roles** y métricas por día, semana, mes y año.
6. **Onboarding simplificado** para comercios sin cuenta de Stripe, y conexión de cuentas existentes (OAuth o llaves de API) para quienes ya usan Stripe.

> **Riesgo de producto identificado:** si solo se ofrece Stripe sin diferenciadores reales, el producto es un "wrapper delgado". Los puntos 1 y 4 son los diferenciadores más fuertes y deben priorizarse en el roadmap.

### 2.3 Alcance del MVP (fase de salida a producción)

**Incluido:**
- Multi-tenant en una sola base de datos MariaDB.
- Conexión de tenants con Stripe por tres métodos: onboarding de plataforma (Account Links), OAuth y API key del comercio (restricted key + publishable key).
- API v1 para crear, consultar, listar y cancelar links; consultar pagos; crear y consultar reembolsos; consultar eventos.
- Página de pago pública con branding, campos configurables del pagador y pago con tarjeta (Payment Element).
- Links de **un solo uso, reabribles** hasta que se pagan o expiran.
- Expiración por defecto, personalizable, con tope máximo.
- Conversión USD→MXN automática (opcional por tenant) con Banxico FIX o tipo de cambio fijo, con paso de confirmación.
- Webhooks salientes firmados con HMAC-SHA256 (formato Standard Webhooks), con reintentos y log de entregas.
- Validación previa al cobro (callback síncrono opcional al sistema del tenant).
- Webhooks entrantes de Stripe (Connect) con procesamiento idempotente y reconciliación.
- Reembolsos totales y parciales desde el panel y la API; registro de disputas.
- Panel del tenant: links, pagos, reembolsos, métricas, usuarios, roles, API keys, webhooks, branding, configuración de Stripe, FX y campos del pagador.
- Panel superadmin: tenants, estados, planes tarifarios, reportes de uso, salud del sistema y auditoría.
- RBAC con permisos propios de la plataforma.
- Modo test y modo live separados.

**Excluido del MVP** (detalle en sección 28): otras pasarelas, Apple Pay/Google Pay, OXXO/SPEI/meses sin intereses, dominios personalizados por tenant, emisión de CFDI, roles personalizados creados por el tenant, firmas asimétricas de webhooks, gestión de webhooks vía API, usuarios en múltiples tenants.

### 2.4 Volumen esperado y su implicación

- **~500 transacciones/mes al inicio** (≈17/día).
- La escalabilidad **no** es el riesgo principal. Los riesgos principales son **correctitud** (dinero, FX, estados, idempotencia), **seguridad** (aislamiento entre tenants, card testing, SSRF) y **operación** (webhooks perdidos, cron caído, backups).
- Consecuencia: arquitectura simple (monolito modular, cola en base de datos, un servidor de aplicación), con el esfuerzo de ingeniería concentrado en pruebas, seguridad y observabilidad.

---

## 3. Registro de decisiones (ADRs)

Formato: **Contexto → Opciones evaluadas → Decisión → Justificación → Consecuencias**. Estado: `DECIDIDO`, `PROPUESTO` (requiere confirmación) o `PENDIENTE`.

Cada ADR debe replicarse como archivo en `docs/adr/NNNN-titulo.md` durante la fase 0.

---

### ADR-001 — Estilo arquitectónico: monolito modular (KISS) · `DECIDIDO`

- **Contexto:** equipo pequeño, volumen bajo, necesidad de salir rápido a producción con alta correctitud.
- **Opciones:**
  - (a) Microservicios. *Pros:* despliegue independiente. *Contras:* complejidad operativa desproporcionada, transacciones distribuidas y observabilidad difícil.
  - (b) **Monolito modular** con límites claros entre módulos. *Pros:* simple, transaccional y fácil de probar. *Contras:* exige disciplina para no acoplar módulos.
  - (c) Monolito sin estructura. *Contras:* deuda técnica rápida.
- **Decisión:** (b).
- **Consecuencias:** un solo repositorio y un solo despliegue. Los módulos se comunican mediante interfaces y eventos de dominio internos, no accediendo a los modelos de otros módulos de forma arbitraria. La extracción futura a servicios queda posible pero no se planea.

### ADR-002 — Tenancy: una sola base de datos compartida con `tenant_id` · `DECIDIDO`

- **Contexto:** la idea inicial era una BD por cliente para aislar la información.
- **Opciones:**
  - (a) BD por tenant. *Pros:* aislamiento fuerte, restauración por cliente. *Contras:* N migraciones, explosión de conexiones, aprovisionamiento por cliente, métricas globales federadas y costo fijo por cliente. Además, se necesita una BD central de todas formas (routing de webhooks de Stripe, API keys, tokens públicos).
  - (b) Schema por tenant. En MariaDB, schema = base de datos; mismos problemas que (a).
  - (c) **BD compartida con columna `tenant_id`** y aislamiento en la aplicación, reforzado en la BD con llaves foráneas compuestas.
  - (d) Híbrido pool + silo para clientes enterprise.
- **Decisión:** (c). El diseño no debe impedir evolucionar a (d) en el futuro.
- **Justificación:** simplicidad operativa y costo; el requisito era "aislar la información", no un requisito regulatorio de separación física.
- **Consecuencias:** el aislamiento es responsabilidad del código, así que se exigen los controles de la sección 6.

### ADR-003 — Motor de base de datos: MariaDB (LTS), autoadministrado · `DECIDIDO`

- **Contexto:** se evaluó PostgreSQL por Row-Level Security (RLS). El equipo decidió MariaDB en servidor propio.
- **Opciones:** PostgreSQL (con RLS), MySQL 8.x, **MariaDB LTS**.
- **Decisión:** MariaDB, **la versión LTS más reciente disponible al iniciar** (11.4 LTS o 11.8 LTS). La **misma versión exacta** en local, CI, staging y producción.
- **Consecuencias:**
  - No hay RLS, así que se aplican los controles compensatorios de la sección 6.
  - Charset `utf8mb4`, collation por defecto `utf8mb4_uca1400_ai_ci`. **`utf8mb4_0900_ai_ci` es exclusiva de MySQL y no existe en MariaDB.**
  - Collation binaria (`ascii_bin`) para tokens, hashes, idempotency keys e IDs externos (ver 6.7).
  - JSON en MariaDB es un alias de `LONGTEXT` con validación `JSON_VALID`; no depender de funciones JSON específicas de MySQL.
  - `SKIP LOCKED` disponible desde 10.6, lo que permite la cola en base de datos.
  - Responsabilidad total del equipo sobre backups, PITR, actualizaciones, TLS y monitoreo (sección 25).

### ADR-004 — Conexión de tenants con Stripe: tres métodos soportados · `DECIDIDO` (con riesgo aceptado para el método 3)

- **Contexto:** la idea inicial era OAuth. El público objetivo principal son comercios **sin pasarela** (muchos sin cuenta de Stripe), pero también se quiere atender a comercios que ya usan Stripe. El responsable del proyecto decidió soportar **las tres formas**.
- **Métodos (enum `connection_method`):**

  | | `platform_onboarding` | `oauth` | `api_key` |
  |---|---|---|---|
  | Mecanismo | Accounts API con controller properties + Account Links (onboarding alojado por Stripe) | OAuth Connect (cuentas Standard existentes) | El comercio registra su **restricted key** (`rk_`) y su **publishable key** (`pk_`) |
  | Público | Comercios sin cuenta de Stripe (principal) | Comercios con cuenta de Stripe que quieren conservarla | Comercios con cuenta de Stripe cuando OAuth no está disponible o no lo desean |
  | ¿Es Stripe Connect? | Sí | Sí | **No** (Stripe no conoce la relación de plataforma) |
  | Llamadas al API | Llave de la plataforma + header `Stripe-Account` | Llave de la plataforma + header `Stripe-Account` | Llave del comercio, sin header |
  | Stripe.js | `pk` de la plataforma + `stripeAccount` | `pk` de la plataforma + `stripeAccount` | **`pk` del comercio**, sin `stripeAccount` |
  | Webhooks de Stripe | Endpoint Connect único de la plataforma | Endpoint Connect único de la plataforma | **Endpoint propio por conexión**, creado por nuestro sistema en la cuenta del comercio, con su propio secret |
  | Secretos del comercio almacenados | Ninguno | Ninguno (solo `stripe_user_id`; no se usa el `access_token`) | **Sí**: restricted key (cifrada con llave dedicada) y secret de webhook |
  | Detección de desconexión | `account.application.deauthorized` | `account.application.deauthorized` | Errores de autenticación + health check periódico |
  | Recomendación | **Default en la UI** | Opción secundaria | Opción avanzada, con advertencias |

- **Riesgos aceptados y avisos de Stripe:**
  - Stripe indica que OAuth **no se recomienda para plataformas Connect nuevas** y que desde 2021 no puede conectar cuentas controladas por otra plataforma con `read_write`. **Verificar en el dashboard de Connect que OAuth esté disponible para nuestra plataforma** antes de implementarlo (sección 29). Si no lo está, el método `oauth` queda deshabilitado por configuración sin afectar a los demás.
  - El método `api_key` convierte a la plataforma en custodia de credenciales de los comercios. **Riesgo de seguridad crítico aceptado por el responsable del proyecto**, mitigado con los controles obligatorios de 12.3.3.
- **Reglas duras del método `api_key`:**
  1. **Solo restricted keys (`rk_test_` / `rk_live_`).** Las secret keys (`sk_`) se rechazan siempre. Justificación: una `sk_` da control total de la cuenta del comercio; una `rk_` bien configurada limita el daño de una filtración a las operaciones necesarias.
  2. La `pk_` y la `rk_` deben pertenecer a **la misma cuenta** y al **mismo modo** (validado al conectar; 12.3.3).
  3. Los permisos de la `rk_` se validan al conectar (ni de menos, ni de más en recursos peligrosos).
  4. Cifrado con una llave dedicada (`GATEWAY_CREDENTIALS_KEY`), distinta de `APP_KEY`.
  5. El tenant acepta en el panel un aviso explícito de riesgo (registrado en el audit log).
- **Consecuencias:**
  - `gateway_connections` incluye `connection_method` y columnas cifradas para credenciales (7.4).
  - `StripeGateway` usa una `StripeClientFactory` que construye el contexto de llamada según el método (12.4.1). **El dominio no conoce el método de conexión.**
  - Tres flujos de conexión en el panel, con pruebas propias (fase 2 dividida en 2A, 2B y 2C).
  - Un tenant tiene como máximo **una conexión activa por proveedor y modo**, sin importar el método. Cambiar de método implica desconectar la anterior (con los links activos manejados según 12.3.4).

### ADR-005 — El link vive en nuestro dominio; el cobro usa PaymentIntent + Payment Element · `DECIDIDO`

- **Opciones:**
  - (a) Redirigir a Stripe Checkout (Checkout Session). *Pros:* menos trabajo. *Contras:* el branding lo controla la configuración de la cuenta conectada, las sesiones expiran (máximo 24 horas) y es difícil insertar la lógica de conversión.
  - (b) **Página propia con Payment Element de Stripe.js, en modo deferred intent + ConfirmationToken.** *Pros:* white label real; el alcance PCI sigue siendo mínimo (SAQ A, porque los datos de tarjeta viven en iframes de Stripe); la expiración es nuestra; permite inspeccionar el país de la tarjeta antes de confirmar (necesario para FX). *Contras:* más UI propia y manejo de 3DS y errores.
- **Decisión:** (b).
- **Consecuencias:** URL pública `https://pay.<dominio>/l/{public_token}`. **Un link nunca equivale a un objeto de Stripe:** el link es nuestro y los PaymentIntents son intentos asociados.

### ADR-006 — Semántica del link: un solo uso, reabrible · `DECIDIDO`

- **Contexto:** se evaluaron links reutilizables (múltiples pagos) y de un solo uso.
- **Decisión:** **un solo uso.** El link puede abrirse cuantas veces se quiera (hoy, mañana, en 5 días) mientras esté vigente y no pagado. **Queda inservible únicamente** cuando se paga, cuando expira o cuando se cancela.
- **Reglas:**
  - Un pago fallido **no** consume el link.
  - Mientras exista un intento en estado `processing` o `requires_action`, no se permite iniciar otro intento en paralelo.
  - **Como máximo un PaymentIntent activo (no terminal) por link.** Si existe uno en `requires_payment_method`, se reutiliza o actualiza en lugar de crear otro. Esto evita cobrar dos veces si el link se abre en dos pestañas o dispositivos.
  - **Si un pago se confirma exitosamente después de la expiración o cancelación, el pago gana:** el dinero ya se movió, así que el link pasa a `paid` y se registra la anomalía.
- **Consecuencias:** los links reutilizables (con `max_uses`) quedan fuera del MVP. El modelo de datos (link 1:N intentos) no los impide a futuro.

### ADR-007 — Dinero: enteros en unidades mínimas; la API acepta decimal como string · `DECIDIDO`

- **Contexto:** la idea inicial era guardar el monto como float/decimal de nuestro lado.
- **Opciones:**
  - (a) `FLOAT`/`DOUBLE`. **Prohibido:** representación binaria inexacta (`0.1 + 0.2 ≠ 0.3`).
  - (b) `DECIMAL(p,2)`. Exacto, pero asume 2 decimales; monedas como JPY (0) o KWD (3) obligarían a migrar en el futuro.
  - (c) **`BIGINT` en unidades mínimas + `CHAR(3)` de moneda**, con una tabla o config de exponentes por moneda.
- **Decisión:** (c) en BD y en el dominio. **La API acepta y devuelve el monto como string decimal** (`"150.50"`), nunca como número JSON (muchos parsers lo convierten a double). La conversión string↔minor se hace en el borde, sin pasar por float, usando `brick/money`.
- **Consecuencias:** la sección 8 define las reglas de parseo, validación y redondeo.

### ADR-008 — Webhooks salientes: HMAC-SHA256 siguiendo la especificación Standard Webhooks · `DECIDIDO`

- **Opciones evaluadas:**
  - (A) **HMAC-SHA256 con timestamp** (estilo Stripe / Standard Webhooks). Simple y ampliamente conocido.
  - (B) Firma asimétrica Ed25519 con llave pública publicada. Más robusta ante filtraciones del lado del cliente, pero menos familiar para integradores pequeños.
  - (C) mTLS. Muy fuerte, pero pesado de operar para comercios pequeños.
  - Complemento: patrón *fetch-back* ("thin events"), donde el receptor confirma el estado consultando nuestra API.
- **Decisión:** **solo (A)** en el MVP, con el formato de Standard Webhooks (headers `webhook-id`, `webhook-timestamp`, `webhook-signature`). Se documenta y recomienda el patrón fetch-back. (B) queda como posible fase futura; el formato elegido la soporta sin romper el contrato.
- **Consecuencias:** sección 15.

### ADR-009 — Conversión USD→MXN: detección por tarjeta + confirmación explícita · `DECIDIDO`

- **Contexto:** Stripe documenta que **las cuentas de Stripe en México solo pueden cobrar tarjetas mexicanas en MXN**. Un link en USD de un comercio con cuenta mexicana no puede cobrarse a una tarjeta mexicana en USD. Una tarjeta extranjera sí puede pagar en USD a una cuenta mexicana, y una cuenta de EE. UU. no tiene la restricción.
- **Opciones:**
  - (a) Cobrar siempre en MXN si la cuenta es mexicana. Simple, pero el pagador extranjero paga en MXN.
  - (b) **Detectar el país de la tarjeta antes de confirmar** (ConfirmationToken → `payment_method_preview.card.country`) y convertir solo cuando aplique.
  - (c) Adaptive Pricing de Stripe. Stripe controla el tipo de cambio y cobra una comisión de conversión al pagador; está orientado a Checkout Sessions. Descartado por perder el control del tipo de cambio.
- **Decisión:** (b), **opcional por tenant** (activado o desactivado en su configuración).
- **Regla de conversión:** aplica si y solo si `país_cuenta_conectada = MX` **Y** `país_tarjeta = MX` **Y** `moneda_link = USD` **Y** `conversión_activa_en_tenant = true`.
- **Flujo decidido (seguridad para el pagador y para el comercio):**
  1. La página muestra el total en USD y, si la conversión está activa y la cuenta es mexicana, una **leyenda informativa** con el equivalente en MXN ("Si pagas con tarjeta emitida en México se cobrarán $X MXN").
  2. Al dar clic en "Pagar", el backend inspecciona el país de la tarjeta **antes de enviar cualquier cargo**.
  3. Si aplica la conversión, **no se envía el cargo**. Se responde a la página con la cotización y la página muestra una **pantalla de confirmación** con el monto exacto en MXN, el tipo de cambio, la fuente y la fecha.
  4. Solo tras la confirmación explícita del pagador se actualiza el PaymentIntent a MXN y se confirma.
  5. **No se deja que Stripe rechace el cargo** como mecanismo de detección: un rechazo genera un intento fallido en la cuenta del comercio y afecta sus señales de riesgo.
- **Si la conversión está desactivada** y se da la combinación restringida: no se envía el cargo, se muestra un mensaje claro al pagador y se registra el evento (útil para que el tenant sepa que pierde ventas).
- **Consecuencias:** sección 13. **Apple Pay y Google Pay deshabilitados en el MVP**, porque la hoja del wallet muestra el monto antes de conocer el país de la tarjeta.

### ADR-010 — Fuente del tipo de cambio: Banxico FIX (SIE API) o tipo fijo por link · `DECIDIDO`

- **Opciones:** tipo fijo definido por el usuario; tipo automático de Banxico; proveedores comerciales de FX.
- **Decisión:** ambos modos, configurables:
  - `banxico_fix`: serie FIX de la API SIE de Banxico, obtenida por un job programado y almacenada localmente (nunca se consulta en línea durante un pago).
  - `fixed`: tipo de cambio enviado por el integrador al crear el link.
- **Markup:** configurable por el tenant sobre el FIX, **con tope** (default de plataforma: máximo 10% = 1000 bps).
- **Quién asume el riesgo cambiario:** el tenant (decidido).
- **Consecuencias:** sección 13.

### ADR-011 — Cobro de la plataforma a sus clientes: externo al flujo de pago · `DECIDIDO`

- **Contexto:** la plataforma cobrará una suscripción mensual y/o una comisión fija por transacción y/o un porcentaje, 100% personalizable por tenant. **Nunca se cobra al pagador.**
- **Opciones:**
  - (a) `application_fee_amount` de Stripe Connect en cada cargo + Stripe Billing para la suscripción.
  - (b) **Cobro externo:** la plataforma calcula el uso y genera un reporte, y la facturación y cobranza ocurren fuera del sistema.
- **Decisión:** (b).
- **Consecuencias:**
  - No se usa `application_fee_amount`, así que los cargos son direct charges limpios.
  - La plataforma **sí** mantiene planes tarifarios versionados y genera **reportes de uso mensuales reproducibles** (sección 21).
  - La comisión fija por transacción **no se revierte** si hay reembolso; el porcentaje se calcula **sobre el volumen bruto cobrado** y tampoco se revierte con reembolsos ni disputas (ADR-012).
  - IVA y facturación de la plataforma a sus clientes: responsabilidad del operador, fuera del sistema.

### ADR-012 — Comisiones de la plataforma ante reembolsos y disputas · `DECIDIDO`

- **Opciones:** (a) cobrar sobre el bruto, sin reversión; (b) cobrar sobre el neto, revirtiendo con reembolsos.
- **Decisión:** (a). La comisión fija no se devuelve y el porcentaje se calcula sobre el monto cobrado bruto.
- **Justificación:** es la práctica común de la industria, se calcula de forma simple y reproducible, no reabre periodos ya cerrados y evita abusos (cobrar y reembolsar para no pagar comisión).
- **Consecuencias:** debe quedar en los términos y condiciones comerciales. El diseño de planes versionados permite una variante sobre neto por tenant a futuro.
- **Base monetaria:** la comisión se calcula sobre el **monto efectivamente cobrado y en su moneda** (si hubo conversión, sobre MXN). Los reportes nunca suman monedas distintas.

### ADR-013 — Suspensión de un tenant: los links vigentes siguen cobrando · `DECIDIDO`

- **Decisión:** un tenant `suspended` **no puede crear links nuevos** (API y panel), pero **sus links vigentes siguen funcionando** hasta pagarse o expirar. El panel queda accesible en solo lectura, con un aviso.
- **Justificación:** los pagadores no tienen la culpa del incumplimiento del tenant, y cortar links emitidos daña la reputación de la plataforma.
- **Consecuencias:** estados y matriz de comportamiento en la sección 21.

### ADR-014 — RBAC con permisos propios; roles como conjuntos de permisos · `DECIDIDO`

- **Decisión:** el código verifica **permisos**, nunca nombres de rol. Los roles son conjuntos de permisos definidos en BD (seeders) y modificables por el superadmin sin desplegar código. Implementación con `spatie/laravel-permission` y su funcionalidad de *teams* (team = tenant).
- **Superadmin separado:** tabla `platform_admins`, guard `platform` y subdominio propio. **Nunca** es "un rol más" dentro de los usuarios del tenant.
- **Consecuencias:** sección 17.

### ADR-015 — API keys en tabla propia, pertenecientes al tenant (no a un usuario) · `DECIDIDO`

- **Opciones:**
  - (a) Laravel Sanctum con `tokenable = Tenant`. Funciona, pero separar test/live con prefijos visibles distintos requiere forzarlo con un modelo personalizado.
  - (b) **Tabla propia `api_keys`** con prefijos por modo (`plk_test_` / `plk_live_`), hash SHA-256, scopes, expiración opcional, `last_used_at` y revocación.
- **Decisión:** (b). Es poco código (un modelo, un generador y un middleware) y da control total.
- **Justificación:** la key pertenece al tenant, así que si el usuario que la creó deja la empresa, la integración no se rompe. Los prefijos visibles evitan usar llaves de producción en pruebas por accidente.
- **Consecuencias:** sección 10.2.

### ADR-016 — Cola y tareas asíncronas: driver `database` de Laravel · `DECIDIDO`

- **Opciones:** Redis + Horizon; SQS; **cola en BD**.
- **Decisión:** driver `database` sobre MariaDB (usa `SKIP LOCKED`). Con ~500 transacciones al mes es suficiente y elimina un componente de infraestructura.
- **Revisión:** migrar a Redis cuando la latencia de la cola o la carga lo justifiquen (sección 24 define la métrica).

### ADR-017 — Fuente de verdad de pagos: BD propia alimentada por webhooks + reconciliación · `DECIDIDO`

- **Decisión:** el panel y la API leen de nuestra BD; **no** se consulta Stripe para renderizar vistas. Los webhooks de Stripe actualizan el estado, y un job de reconciliación periódico corrige los eventos perdidos. Cada handler de webhook **vuelve a consultar el objeto en Stripe** antes de actualizar (lo que resuelve el desorden de eventos; con el volumen esperado, el costo es despreciable).

### ADR-018 — Métodos de pago del MVP: solo tarjeta · `DECIDIDO`

- **Decisión:** `payment_method_types = ['card']`. OXXO, SPEI, meses sin intereses, Apple Pay y Google Pay quedan para fases posteriores.
- **Justificación:** los métodos asíncronos (OXXO, SPEI) rompen el supuesto de confirmación inmediata y requieren estados adicionales; los wallets entran en conflicto con el flujo de FX (ADR-009). El modelo de estados ya contempla `processing` para no rediseñar después.

### ADR-019 — Abstracción de pasarela (puerto/adaptador) desde el día uno, sin sobreingeniería · `DECIDIDO`

- **Decisión:** existe una interfaz `PaymentGateway` que el dominio usa y una implementación `StripeGateway`. La interfaz se diseña pensando en **dos** pasarelas (Stripe con iframe y confirmación en página; Mercado Pago con redirección), aunque solo se implemente Stripe. Se elige el adaptador con una *factory* simple por `provider`, sin plugins ni carga dinámica.
- **Consecuencias:** sección 12.1. Ningún código fuera del adaptador conoce IDs ni estados de Stripe.

### ADR-020 — Identificadores: ULID como PK, con prefijos en la API · `DECIDIDO`

- **Decisión:** las entidades expuestas usan ULID (`CHAR(26)`, collation `ascii_bin`) como llave primaria. La API los muestra con prefijo de tipo: `plink_01J...`, `pay_01J...`, `re_01J...`, `evt_01J...`. El prefijo se agrega y valida en la capa HTTP; en BD se guarda solo el ULID.
- **Justificación:** son no secuenciales (dificultan la enumeración), ordenables por tiempo y no requieren coordinación. El prefijo evita confusiones entre tipos de ID en soporte y en logs.
- **Excepción:** el `public_token` del link **no** es su ID; es un secreto aleatorio independiente (sección 11.1).

### ADR-021 — Campos del pagador: catálogo fijo, configurable por tenant y por link · `DECIDIDO`

- **Decisión:** la plataforma ofrece un catálogo de campos (email, nombre, teléfono, dirección, etc.). Cada tenant define para cada campo si está `hidden`, es `optional` o es `required`, y cada link puede sobrescribir esa configuración vía API.
- **Consecuencias:** sección 19. La PII se cifra en reposo y tiene política de retención.

### ADR-022 — Notificaciones: solo correo, sin interacción con pagadores · `DECIDIDO`

- **Decisión:** la plataforma no envía correos a los pagadores. Los correos operativos van a los usuarios del tenant con permiso y a los superadmins, según el evento (sección 22). Los recibos de Stripe al pagador son una opción del tenant (desactivada por defecto).
- **Supuesto a confirmar:** la instrucción original fue "enviar correo solo al admin de la plataforma". Se interpreta que las alertas de plataforma van al superadmin y las operativas del tenant a sus usuarios (sección 29).

### ADR-023 — Emisión de facturas (CFDI): fuera de alcance · `DECIDIDO`

- **Decisión:** la plataforma no emite CFDI ni archivos fiscales. El tenant puede usar `metadata` para relacionar pagos con su propia facturación. No se agregan campos fiscales obligatorios al checkout; `tax_id` existe en el catálogo como campo opcional desactivado por defecto (sección 19).

### ADR-024 — Validación previa al cobro: callback síncrono, separado de los webhooks · `DECIDIDO`

- **Contexto:** el responsable del proyecto quiere que, antes de cobrar, la plataforma consulte al sistema del tenant para validar la operación (stock, precio vigente, orden aún pendiente, etc.). Si la validación falla, no se cobra; si pasa, se cobra, y los webhooks posteriores solo notifican.
- **Por qué no es un webhook:** los webhooks son asíncronos, con reintentos de hasta ~27 horas, entrega "al menos una vez" y sin contenido de respuesta relevante. La validación es **síncrona** (el pagador está esperando), necesita una **respuesta con decisión** y no admite reintentos largos.
- **Opciones sobre el momento de la validación:**
  - (a) Al abrir el link. *Contras:* la validación queda obsoleta si el pagador tarda en pagar y bloquea la carga de la página por el servidor del comercio.
  - (b) **Justo antes de confirmar el cobro** (después de capturar la tarjeta, de aplicar los rate limits y Turnstile, y de la confirmación FX si aplica). *Pros:* es la última oportunidad real de detener el cobro y los datos están completos.
  - (c) Ambos.
- **Decisión:** (b) en el MVP. La opción (a) queda como posible fase futura; mientras tanto, el evento asíncrono `payment_link.opened` sigue existiendo, y el integrador puede cancelar un link vía API cuando quiera.
- **Características:**
  - **Opcional por tenant** y activable/desactivable **por link** (`pre_payment_validation: true|false` al crear, con default del tenant).
  - URL configurada en el panel (una por modo), firmada con el mismo esquema HMAC de Standard Webhooks y protegida con las mismas reglas SSRF.
  - Contrato de respuesta explícito: `approve` o `reject` (con código y mensaje opcional para el pagador).
  - **Timeout total de 5 segundos.** Sin reintentos (salvo un reintento inmediato ante un fallo de conexión antes de enviar datos).
  - **Política ante fallo** (timeout, error, respuesta inválida) configurable por el tenant: `fail_closed` (**default**: no se cobra) o `fail_open` (se cobra de todas formas y se registra).
  - Detalle completo en la sección 15.8.
- **Consecuencias:**
  - El servidor del tenant queda en la ruta crítica del pago cuando la validación está activa; su disponibilidad afecta sus ventas (documentarlo claramente).
  - Nuevas tablas o columnas: configuración en la tabla `validation_endpoints` (7.4) y log en `validation_calls` (7.6).
  - Riesgo residual inherente: entre la aprobación y la confirmación del cobro pasan segundos. Si el comercio necesita garantía de stock, debe **reservarlo** al aprobar y liberarlo si no recibe `payment.succeeded` en un tiempo razonable (documentarlo para los integradores).


---

## 4. Arquitectura general

### 4.1 Superficies del sistema

| Superficie | Host sugerido | Autenticación | Contexto de tenant |
|---|---|---|---|
| API pública v1 | `api.<dominio>` | API key (`Authorization: Bearer plk_...`) | Derivado de la API key |
| Página de pago | `pay.<dominio>` | Ninguna (token secreto en la URL) | Derivado del `public_token` del link |
| Panel del tenant | `app.<dominio>` | Sesión + 2FA | Derivado del usuario autenticado |
| Panel superadmin | `admin.<dominio>` | Guard `platform` + 2FA obligatorio + (recomendado) allowlist de IP o VPN | Contexto de plataforma explícito y auditado |
| Webhooks de Stripe (Connect) | `api.<dominio>/webhooks/stripe/connect/{mode}` | Firma de Stripe (secret por modo) | Derivado de `event.account` |
| Webhooks de Stripe (direct, método `api_key`) | `api.<dominio>/webhooks/stripe/direct/{connection_id}` | Firma de Stripe (secret por conexión) | Derivado de la conexión |
| Validación previa (saliente) | URL del tenant | Firma HMAC nuestra | — |

Todas las superficies se sirven desde la misma aplicación Laravel, con rutas separadas por dominio (`Route::domain(...)`), middleware específico para cada una y cookies de sesión aisladas por subdominio (no se comparten cookies entre `app.` y `admin.`).

### 4.2 Componentes

```
                    ┌──────────────────────────────────────────────┐
 Integrador ──API──▶│                                              │
                    │                 Laravel app                  │
 Pagador ──HTTPS──▶ │  ┌────────┐ ┌─────────┐ ┌──────────────────┐ │──▶ Stripe API
                    │  │  API   │ │Checkout │ │ Paneles Filament │ │
 Usuario ──HTTPS──▶ │  └────────┘ └─────────┘ └──────────────────┘ │
                    │        Módulos de dominio (sección 4.3)      │◀── Webhooks Stripe
                    └───────────────┬──────────────────────────────┘
                                    │
                         ┌──────────▼──────────┐       ┌───────────────┐
                         │  MariaDB (única BD) │◀─────▶│   Workers     │──▶ URLs de webhooks
                         │  + tabla jobs       │       │ (queue:work)  │    de los tenants
                         └─────────────────────┘       └───────────────┘
                                                        ┌───────────────┐
                                                        │  Scheduler    │──▶ Banxico SIE API
                                                        │ (cron 1/min)  │
                                                        └───────────────┘
```

### 4.3 Módulos de dominio

Estructura sugerida: `app/Modules/<Modulo>/` con subcarpetas `Models`, `Actions`, `Data` (DTOs), `Enums`, `Events`, `Jobs`, `Http` (controllers, requests, resources), `Policies`, `Services`, `Exceptions`. Cada módulo expone un conjunto pequeño de *Actions* o *Services* públicos; los demás módulos no tocan sus modelos directamente cuando existe una Action para ello.

| Módulo | Responsabilidad |
|---|---|
| `Tenancy` | Tenants, `TenantContext`, trait `BelongsToTenant`, resolución de tenant por superficie, estados del tenant. |
| `Identity` | Usuarios del tenant, autenticación, 2FA, invitaciones, re-autenticación para acciones sensibles. |
| `Access` | Permisos y roles (spatie/permission con teams), policies. |
| `PlatformAdmin` | Superadmins, impersonation auditada, vistas globales. |
| `ApiKeys` | Emisión, hash, verificación, scopes y revocación de API keys. |
| `Gateways` | Puerto `PaymentGateway`, adaptador `StripeGateway`, `StripeClientFactory`, conexiones de tenants con pasarelas (`gateway_connections`), flujos de conexión (`platform_onboarding`, `oauth`, `api_key`), cifrado de credenciales, health checks. |
| `PaymentLinks` | Creación, expiración, cancelación y consulta de links; máquina de estados del link. |
| `Checkout` | Página pública, cotización, inicio y confirmación de intentos, protección anti card testing. |
| `Payments` | Intentos de pago, reembolsos, disputas; máquina de estados del intento. |
| `Fx` | Tipos de cambio (Banxico), cotizaciones, reglas de conversión. |
| `ProviderEvents` | Ingesta, verificación, almacenamiento y despacho de webhooks entrantes; reconciliación. |
| `Webhooks` | Endpoints de los tenants, outbox de eventos, entrega firmada, reintentos, log de entregas, protección SSRF compartida, y la validación previa al cobro (`validation_endpoints`, `validation_calls`, cliente síncrono). |
| `Branding` | Logo, colores y nombre visible; validación de contraste. |
| `PayerFields` | Catálogo de campos del pagador, configuración por tenant y por link, validación y almacenamiento cifrado. |
| `Reporting` | Rollups diarios, métricas, reportes de uso mensuales. |
| `Billing` | Planes tarifarios versionados y cálculo de comisiones para los reportes (sin cobro). |
| `Audit` | Log de auditoría inmutable. |
| `Notifications` | Correos operativos. |
| `Shared` | Money, IDs con prefijo, errores de API, utilidades transversales. **No debe convertirse en un cajón de sastre.** |

### 4.4 Principios de diseño aplicados

- **Actions de un solo propósito** (`CreatePaymentLink`, `StartPaymentAttempt`, `ConfirmPaymentAttempt`, `RefundPayment`...) que encapsulan la lógica de negocio y son reutilizadas por la API, el panel y los jobs. Los controllers y los recursos de Filament **no** contienen lógica de negocio.
- **DTOs inmutables** para la entrada y salida de las Actions.
- **Enums de PHP** para estados, modos y monedas.
- **Eventos de dominio internos** (`PaymentLinkPaid`, `PaymentAttemptFailed`...), que disparan listeners (outbox de webhooks, notificaciones, rollups). El listener que escribe en el outbox se ejecuta **dentro de la misma transacción** (sección 15.4).
- **Transacciones explícitas** con `DB::transaction()` y **bloqueos de fila** (`lockForUpdate()`) en toda transición de estado del link o del intento.

---

## 5. Stack tecnológico

> Usar **las versiones estables más recientes al momento de iniciar** y fijarlas en `composer.lock` / `package-lock.json`. Verificar compatibilidad entre Laravel, Filament y los paquetes antes de fijar versiones.

| Área | Elección | Notas |
|---|---|---|
| Lenguaje | PHP 8.3+ (preferente 8.4) | Enums, readonly, tipos estrictos (`declare(strict_types=1);` en todos los archivos). |
| Framework | Laravel (versión estable actual) | Usar el driver `mariadb` de Laravel. |
| Paneles | Filament (versión estable actual) | Dos paneles: `app` (tenant) y `admin` (plataforma). |
| BD | MariaDB LTS (11.4 u 11.8) | Ver ADR-003 y sección 6. |
| Cola | Laravel Queue, driver `database` | Supervisor para los workers. |
| Stripe | `stripe/stripe-php` | Versión de API de Stripe **fijada** explícitamente en la configuración del cliente. |
| Dinero | `brick/money` | Nunca aritmética manual con floats. |
| Permisos | `spatie/laravel-permission` (teams) | Team = tenant. |
| 2FA | Mecanismo MFA de Filament o Laravel Fortify | TOTP + códigos de recuperación. |
| Frontend del checkout | Blade + JS ligero (Alpine.js o JS vanilla) + Stripe.js | Sin SPA. La página debe ser liviana y rápida. |
| Anti-bots | Cloudflare Turnstile | Solo en el checkout, tras intentos fallidos (sección 11.7). |
| Correo | SMTP transaccional (Postmark, SES o similar) | Configurable. |
| Errores | Sentry o GlitchTip (autoalojado) | Con scrubbing de PII. |
| Pruebas | Pest + Larastan (nivel máximo alcanzable) + Laravel Pint | CI obligatorio. |
| Desarrollo local | Docker (Laravel Sail o compose propio) con **la misma versión de MariaDB** que producción | Stripe CLI para reenviar webhooks. |

**Paquetes evaluados y descartados:**
- `laravel/cashier-stripe`: descartado, porque la plataforma no cobra a sus tenants vía Stripe (ADR-011).
- `spatie/laravel-webhook-server`: descartado. No persiste el historial de entregas que necesita el panel ni incluye protección SSRF, así que habría que envolverlo de todas formas. Se implementa un módulo propio pequeño (sección 15).
- `stancl/tenancy`: descartado para el modelo de una sola BD. Un trait propio es más simple y transparente (sección 6).
- Laravel Horizon / Redis: no son necesarios por ahora (ADR-016).
- Laravel Octane: descartado. El estado persistente entre requests puede filtrar el contexto de tenant entre peticiones, y el volumen no lo justifica.

---

## 6. Multi-tenancy y aislamiento de datos (MariaDB sin RLS)

Como MariaDB no tiene Row-Level Security, el aislamiento se construye en **capas de defensa**. Ninguna capa es suficiente por sí sola; todas son obligatorias.

### 6.1 `TenantContext`

Servicio *scoped* por request/job que guarda el tenant actual y el modo (`livemode`).

```php
final class TenantContext
{
    private ?string $tenantId = null;
    private ?bool $livemode = null;
    private bool $platformMode = false;

    public function set(string $tenantId, bool $livemode): void { /* ... */ }
    public function idOrNull(): ?string { return $this->tenantId; }
    public function idOrFail(): string { return $this->tenantId ?? throw new MissingTenantContextException(); }
    public function livemode(): bool { return $this->livemode ?? throw new MissingTenantContextException(); }

    /** Ejecuta un callback en contexto de plataforma (sin filtro de tenant). Siempre auditado. */
    public function runAsPlatform(string $reason, callable $callback): mixed { /* registra en audit_logs */ }
}
```

- Se registra como `scoped` en el contenedor (no `singleton`), para que se reinicie en cada request y en cada job.
- Existe un middleware por superficie que lo establece (6.3).

### 6.2 Trait `BelongsToTenant` con global scope *fail-closed*

```php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $context = app(TenantContext::class);

            if ($context->isPlatformMode()) {
                return; // contexto de plataforma explícito y auditado
            }

            $tenantId = $context->idOrNull()
                ?? throw new MissingTenantContextException(static::class);

            $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function (Model $model): void {
            $model->tenant_id ??= app(TenantContext::class)->idOrFail();
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new TenantReassignmentException(); // tenant_id es inmutable
            }
        });
    }
}
```

- **Fail-closed:** sin contexto, la consulta lanza una excepción en lugar de devolver datos de todos los tenants.
- Si la tabla tiene `livemode`, se aplica un scope análogo `BelongsToMode`, para que los datos de test y de live nunca se mezclen en vistas ni en la API.

### 6.3 Resolución del tenant por superficie

| Superficie | Cómo se resuelve | Notas |
|---|---|---|
| API | Middleware `AuthenticateApiKey`: busca la key por hash, obtiene `tenant_id` y `livemode`, y llama a `TenantContext::set()`. | La tabla `api_keys` se consulta en contexto de plataforma, porque es la puerta de entrada. |
| Panel tenant | Middleware tras la autenticación: `tenant_id` del usuario, y `livemode` según el selector test/live de la sesión. | Un usuario pertenece a un solo tenant en el MVP. |
| Checkout | `public_token` → link (consulta en contexto de plataforma vía un repositorio dedicado `PaymentLinkLookup`) → `TenantContext::set()` con el tenant y el modo del link. | Solo ese repositorio puede buscar links sin contexto. |
| Webhooks Stripe | Connect: `event.account` → `gateway_connections`; direct: `connection_id` de la URL (contexto de plataforma en ambos) → `TenantContext::set()`. | Si no existe la conexión, se guarda el evento como `unroutable` y se alerta. |
| Jobs | Cada job tenant-aware implementa la interfaz `TenantAware` (propiedades `tenantId`, `livemode`). Un job middleware `RestoreTenantContext` restablece el contexto antes de `handle()`. | Los jobs sin contexto que consulten tablas de tenant fallan por diseño (6.2). |
| Consola / scheduler | Los comandos globales iteran tenants explícitamente y establecen el contexto en cada iteración, o corren en `runAsPlatform()` con motivo. | |

### 6.4 Llaves foráneas compuestas con `tenant_id`

Toda relación entre tablas de tenant incluye `tenant_id` en la llave foránea. Así, la BD hace imposible que un registro del tenant A apunte a uno del tenant B, aunque el código tenga un bug.

```sql
-- En la tabla padre
ALTER TABLE payment_links ADD UNIQUE KEY uq_payment_links_tenant_id (tenant_id, id);

-- En la tabla hija
ALTER TABLE payment_attempts
  ADD CONSTRAINT fk_payment_attempts_link
  FOREIGN KEY (tenant_id, payment_link_id) REFERENCES payment_links (tenant_id, id);
```

Esta regla aplica a: `payment_attempts → payment_links`, `fx_quotes → payment_links`, `refunds → payment_attempts`, `disputes → payment_attempts`, `webhook_deliveries → webhook_endpoints` y `webhook_deliveries → webhook_events`, `payer_details → payment_attempts`, y cualquier relación nueva.

### 6.5 Prohibiciones verificadas en CI

- **Regla de Larastan/PHPStan personalizada** que falla si se usa `DB::table('<tabla_de_tenant>')`, `DB::select/insert/update/delete` con SQL crudo sobre tablas de tenant, o `withoutGlobalScope(s)` fuera de las clases permitidas (lista blanca explícita: `PaymentLinkLookup`, `ApiKeyAuthenticator`, `GatewayConnectionResolver`, `OAuthStateStore`, el módulo `PlatformAdmin` y el módulo `Reporting` para rollups).
- La lista de tablas de tenant se mantiene en un solo lugar (`config/tenancy.php`) y la usan tanto la regla de análisis como las pruebas.

### 6.6 Pruebas de aislamiento obligatorias

- Para **cada endpoint** de la API y **cada recurso** del panel existe una prueba: con las credenciales del tenant A, acceder, modificar o listar recursos del tenant B debe responder **`404`** (no `403`, que confirmaría la existencia del recurso) y la lista no debe incluir recursos ajenos.
- Un helper reutilizable (`assertTenantIsolation(...)`) y un *dataset* de Pest con todas las rutas hacen que agregar un endpoint sin su prueba de aislamiento sea detectable (por ejemplo, una prueba que compara las rutas registradas contra las rutas cubiertas).
- Una prueba que verifica que **todo modelo** que use `tenant_id` tenga el trait `BelongsToTenant`.

### 6.7 Charset y collations

- **Por defecto de BD y tablas:** `utf8mb4` / `utf8mb4_uca1400_ai_ci`. Configurar en `config/database.php` (conexión `mariadb`) y en el servidor.
- **Columnas con collation binaria (`ascii_bin` o `VARBINARY`):**
  - IDs ULID (`CHAR(26) ascii_bin`).
  - `public_token` de links.
  - `key_hash` y `key_prefix` de API keys.
  - `idempotency_key`.
  - IDs de Stripe (`acct_`, `pi_`, `re_`, `dp_`, `evt_`), porque **son sensibles a mayúsculas**.
  - Hashes y firmas en general.
- **Motivo:** con una collation `_ci`, dos tokens base62 que difieren solo en mayúsculas serían "iguales", lo que provoca colisiones en índices `UNIQUE` y coincidencias falsas en búsquedas de seguridad.
- En las migraciones de Laravel se usa `->charset('ascii')->collation('ascii_bin')` en esas columnas.

### 6.8 Configuración de MariaDB obligatoria

- `sql_mode` estricto: incluir `STRICT_TRANS_TABLES`, `ERROR_FOR_DIVISION_BY_ZERO`, `NO_ENGINE_SUBSTITUTION`, `NO_ZERO_DATE`, `NO_ZERO_IN_DATE`. En Laravel, `'strict' => true` en la conexión. **Verificar que el servidor no lo sobrescriba.** Sin modo estricto, MariaDB trunca los datos en silencio.
- Zona horaria de la sesión en UTC (`'timezone' => '+00:00'` en la conexión). La aplicación guarda todo en UTC y convierte a la zona del tenant solo al presentar.
- Fechas de negocio con **`DATETIME(6)`** en UTC, no `TIMESTAMP` (límite de 2038 y conversiones implícitas de zona horaria).
- Motor InnoDB en todas las tablas; nivel de aislamiento por defecto `REPEATABLE READ`, con bloqueos explícitos donde haya transiciones de estado.
- Usuario de BD de la aplicación **sin** privilegios DDL (`CREATE`, `ALTER`, `DROP`) en producción; las migraciones corren con un usuario distinto durante el despliegue (sección 25).

---

## 7. Modelo de datos

> Convenciones: IDs `CHAR(26) ascii_bin` (ULID) salvo indicación contraria; montos `BIGINT` en unidades mínimas; monedas `CHAR(3)` en mayúsculas; porcentajes en `INT` bps; tipos de cambio `DECIMAL(18,6)`; fechas `DATETIME(6)` UTC; `created_at` y `updated_at` en todas las tablas. Las tablas de tenant tienen `tenant_id` (FK a `tenants`) y, cuando aplica, `livemode TINYINT(1)`. Se muestran columnas y restricciones clave; los índices adicionales se deciden según las consultas reales.

### 7.1 Plataforma

**`platform_admins`**
- `id`, `name`, `email` (único), `password`, `two_factor_secret` (cifrado), `two_factor_recovery_codes` (cifrado), `two_factor_confirmed_at`, `role` (`superadmin` | `support_readonly`), `last_login_at`, `disabled_at`.

**`tenants`**
- `id`, `legal_name`, `display_name`, `status` (`pending_onboarding` | `active` | `grace` | `suspended` | `closed`), `status_reason`, `status_changed_at`.
- `timezone` (IANA, default `America/Mexico_City`), `default_locale` (`es` | `en`).
- `support_email`, `privacy_notice_url` (aviso de privacidad del tenant, requerido si recolecta datos del pagador).
- `allowed_return_domains` (JSON: dominios permitidos para `return_url`).
- `settings` (JSON validado por un DTO: expiración por defecto, FX, recibos de Stripe, etc.; ver 7.3).
- `closed_at`.

**`tenant_pricing_plans`** (versionado; sección 21)
- `id`, `tenant_id`, `effective_from` (`DATE`), `effective_to` (`DATE` nullable).
- `subscription_amount_minor`, `subscription_currency` (nullable).
- `fee_fixed_amount_minor`, `fee_fixed_currency` (nullable).
- `fee_percent_bps` (nullable).
- `fee_min_minor`, `fee_max_minor` (nullable, en la moneda del fijo).
- `notes`, `created_by_admin_id`.
- Restricción: sin solapamiento de vigencias por tenant (validado en la aplicación dentro de una transacción con bloqueo).

**`exchange_rates`**
- `id`, `source` (`banxico_fix`), `base_currency` (`USD`), `quote_currency` (`MXN`), `rate` (`DECIMAL(18,6)`), `rate_date` (`DATE`, fecha de publicación), `fetched_at`, `raw_payload` (JSON).
- Único: `(source, base_currency, quote_currency, rate_date)`.

**`audit_logs`** (append-only; la aplicación no expone update ni delete)
- `id`, `tenant_id` (nullable para acciones de plataforma), `actor_type` (`user` | `platform_admin` | `api_key` | `system`), `actor_id`, `action` (por ejemplo `payment_link.canceled`, `api_key.created`, `gateway.connected`, `refund.created`, `role.assigned`, `impersonation.started`), `subject_type`, `subject_id`, `changes` (JSON: antes/después, **sin secretos**), `ip`, `user_agent`, `request_id`, `created_at`.

### 7.2 Identidad y acceso

**`users`** (usuarios de tenant)
- `id`, `tenant_id`, `name`, `email` (**único global** en el MVP: un usuario pertenece a un solo tenant), `password`, `two_factor_*`, `email_verified_at`, `last_login_at`, `disabled_at`.

**Tablas de `spatie/laravel-permission`** con teams habilitado (`team_id` = `tenant_id`). Los roles de sistema se crean con seeders (sección 17).

**`user_invitations`**
- `id`, `tenant_id`, `email`, `role_name`, `token_hash` (`ascii_bin`), `expires_at`, `accepted_at`, `invited_by_user_id`.

**`api_keys`**
- `id`, `tenant_id`, `livemode`, `name`, `key_prefix` (primeros 12 caracteres visibles, por ejemplo `plk_live_a1b2`, `ascii_bin`), `key_hash` (SHA-256 hex, `ascii_bin`, único), `scopes` (JSON: lista de permisos de API), `last_used_at`, `last_used_ip`, `expires_at` (nullable), `revoked_at`, `created_by_user_id`.

### 7.3 Configuración del tenant (`tenants.settings`, JSON validado)

```json
{
  "links": {
    "default_expiration_hours": 168,
    "max_expiration_hours": 2160
  },
  "fx": {
    "conversion_enabled": false,
    "default_mode": "banxico_fix",
    "markup_bps": 0,
    "quote_validity_minutes": 30
  },
  "checkout": {
    "send_stripe_receipts": false,
    "locale": "es"
  },
  "payer_fields": {
    "email": "required",
    "full_name": "optional",
    "phone": "hidden"
  }
}
```

- Límites de plataforma (no configurables por el tenant, en `config/paylink.php`): `max_expiration_hours` absoluto = 2160 (90 días), `markup_bps` máximo = 1000, expiración mínima = 15 minutos.
- La configuración se lee siempre a través de un DTO `TenantSettings` con valores por defecto, nunca como arreglo suelto.

### 7.4 Pasarelas

**`gateway_connections`**
- `id`, `tenant_id`, `livemode`, `provider` (`stripe`).
- `connection_method` (`platform_onboarding` | `oauth` | `api_key`; ADR-004).
- `provider_account_id` (`acct_...`, `ascii_bin`). En `api_key` se obtiene con `GET /v1/account` usando la restricted key.
- `country` (ISO-3166 alpha-2, por ejemplo `MX`), `default_currency`.
- `status` (`onboarding` | `active` | `restricted` | `invalid_credentials` | `disconnected`).
- `charges_enabled`, `payouts_enabled`, `requirements` (JSON: snapshot de `requirements`; en `api_key` puede no estar disponible según los permisos de la llave).
- **Solo para `api_key`** (todas `NULL` en los otros métodos):
  - `credentials_secret` (`TEXT`, restricted key cifrada con `GATEWAY_CREDENTIALS_KEY`; nunca con `APP_KEY`).
  - `credentials_publishable` (`VARCHAR(255)`, publishable key; no es secreta, pero se guarda junto con la conexión para inicializar Stripe.js).
  - `credentials_fingerprint` (`CHAR(64) ascii_bin`: SHA-256 de la restricted key, para detectar si la misma llave se registra en dos tenants).
  - `credentials_last4` (para mostrarla enmascarada: `rk_live_…a1b2`).
  - `credentials_key_version` (entero; versión de la llave de cifrado, para rotarla).
  - `provider_webhook_endpoint_id` (`we_...`, `ascii_bin`): endpoint creado por la plataforma en la cuenta del comercio.
  - `provider_webhook_secret` (cifrado): signing secret de ese endpoint.
  - `validated_permissions` (JSON: resultado de la validación de permisos al conectar).
  - `last_health_check_at`, `last_health_check_status`.
- **Solo para `oauth`:** `oauth_scope` (`read_write`). El `access_token` devuelto por Stripe **no se almacena**: las llamadas se hacen con la llave de la plataforma + `Stripe-Account`, como indica Stripe.
- `risk_acknowledged_at`, `risk_acknowledged_by_user_id` (obligatorio para `api_key`).
- `connected_at`, `disconnected_at`, `disconnect_reason`, `last_synced_at`.
- Único: `(provider, provider_account_id, livemode)` (una misma cuenta de Stripe no puede vincularse a dos tenants). Único: `(tenant_id, provider, livemode)` para conexiones no desconectadas (una conexión activa por proveedor y modo; se implementa con una columna generada análoga a `active_link_id`). Único: `credentials_fingerprint` cuando no es nulo.

**`validation_endpoints`** (ADR-024; una por tenant y modo)
- `id`, `tenant_id`, `livemode`, `url` (`VARCHAR(2048)`), `secret` (cifrado, formato `whsec_...`), `previous_secret` (cifrado, nullable), `previous_secret_expires_at`.
- `enabled_by_default` (si los links nuevos usan la validación cuando la API no indica nada).
- `failure_policy` (`fail_closed` | `fail_open`; default `fail_closed`).
- `status` (`enabled` | `disabled`), `consecutive_failures`, `last_failure_at`.
- Único: `(tenant_id, livemode)`.

### 7.5 Links y pagos

**`payment_links`**
- `id`, `tenant_id`, `livemode`.
- `public_token` (`VARCHAR(64) ascii_bin`, único; ver 11.1).
- `status` (`active` | `processing` | `paid` | `expired` | `canceled`).
- `amount_minor`, `currency` (`USD` | `MXN`).
- `description` (texto que ve el pagador; máximo 500 caracteres).
- `metadata` (JSON; privado, **nunca** se muestra al pagador).
- `client_reference_id` (`VARCHAR(200)`, nullable; referencia del integrador, por ejemplo su número de orden).
- `fx_mode` (`none` | `banxico_fix` | `fixed`; se resuelve al crear según la configuración del tenant y la solicitud), `fx_fixed_rate` (`DECIMAL(18,6)` nullable).
- `payer_fields_config` (JSON; configuración efectiva = tenant + override del link, congelada al crear).
- `return_url` (nullable; validada contra `allowed_return_domains`).
- `pre_payment_validation` (`TINYINT(1)`; congelado al crear, según la solicitud o el `enabled_by_default` del tenant).
- `locale`.
- `expires_at`, `paid_at`, `canceled_at`, `cancel_reason`, `expired_at`.
- `first_opened_at`, `last_opened_at`, `open_count`.
- `refund_status` (`none` | `partial` | `full`), `dispute_status` (`none` | `open` | `won` | `lost`).
- `created_via` (`api` | `panel`), `created_by_actor_type`, `created_by_actor_id`.
- `idempotency_key` (nullable, `ascii_bin`).
- Únicos: `public_token`; `(tenant_id, livemode, idempotency_key)`.
- Índices: `(tenant_id, livemode, status, created_at)`, `(status, expires_at)` para el job de expiración.

**`fx_quotes`** (inmutable una vez creada)
- `id`, `tenant_id`, `payment_link_id`.
- `source` (`banxico_fix` | `fixed`), `exchange_rate_id` (nullable si es `fixed`), `rate` (`DECIMAL(18,6)`), `rate_date` (`DATE` nullable), `markup_bps`, `effective_rate` (`DECIMAL(18,6)` = rate × (1 + markup)).
- `original_amount_minor`, `original_currency` (`USD`), `converted_amount_minor`, `converted_currency` (`MXN`).
- `expires_at`, `created_at`.

**`payment_attempts`**
- `id`, `tenant_id`, `livemode`, `payment_link_id`.
- `provider` (`stripe`), `provider_payment_id` (`pi_...`, `ascii_bin`), `provider_account_id`, `gateway_connection_id` (conexión con la que se creó; los reembolsos y consultas usan siempre esta conexión).
- `status` (`requires_payment_method` | `requires_confirmation` | `requires_action` | `processing` | `succeeded` | `failed` | `canceled`).
- `amount_minor`, `currency` (**monto y moneda realmente cobrados**).
- `original_amount_minor`, `original_currency` (monto y moneda del link).
- `fx_quote_id` (nullable; presente solo si hubo conversión).
- `card_country` (nullable), `card_brand` (nullable), `card_last4` (nullable). Nunca se guardan PAN ni CVC; no los recibimos.
- `failure_count` (rechazos acumulados), `last_failure_code`, `last_failure_message` (del proveedor, saneado), `last_decline_code`. El historial completo vive en `payment_attempt_failures` (9.2).
- `client_ip`, `user_agent` (para análisis de fraude; retención limitada).
- `succeeded_at`, `failed_at`, `canceled_at`.
- `amount_refunded_minor` (desnormalizado, mantenido transaccionalmente).
- Únicos: `(provider, provider_payment_id)`.
- **Regla "un intento activo por link":** columna generada `active_link_id` = `payment_link_id` cuando el estado no es terminal, `NULL` cuando sí lo es, con índice `UNIQUE`. MariaDB permite múltiples `NULL` en índices únicos, así que la BD garantiza como máximo un intento activo por link.

  ```sql
  active_link_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin
    AS (IF(status IN ('requires_payment_method','requires_confirmation','requires_action','processing'),
           payment_link_id, NULL)) PERSISTENT,
  UNIQUE KEY uq_one_active_attempt_per_link (active_link_id)
  ```

**`payer_details`** (PII, cifrada)
- `id`, `tenant_id`, `payment_attempt_id` (único), `data` (`TEXT`, cifrado con el cast `encrypted:array` de Laravel), `purge_after` (`DATETIME`, según la política de retención), `purged_at`.
- Separada de `payment_attempts` para poder purgarla sin tocar los registros financieros.

**`refunds`**
- `id`, `tenant_id`, `livemode`, `payment_attempt_id`, `provider_refund_id` (`re_...`, `ascii_bin`, único, nullable hasta que Stripe responda), `amount_minor`, `currency`, `status` (`pending` | `succeeded` | `failed` | `canceled`), `reason` (`requested_by_customer` | `duplicate` | `fraudulent` | `other`), `note` (interno), `failure_reason`, `origin` (`api` | `panel` | `provider_dashboard`), `created_by_actor_type`, `created_by_actor_id`, `idempotency_key`, `succeeded_at`.

**`disputes`**
- `id`, `tenant_id`, `livemode`, `payment_attempt_id`, `provider_dispute_id` (`dp_...`, `ascii_bin`, único), `amount_minor`, `currency`, `reason`, `status` (normalizado: `needs_response` | `under_review` | `won` | `lost` | `warning_closed`), `evidence_due_by`, `opened_at`, `closed_at`.

### 7.6 Eventos y webhooks

**`provider_events`** (webhooks entrantes de Stripe)
- `id`, `provider`, `provider_event_id` (`evt_...`, `ascii_bin`, **único**), `provider_account_id`, `livemode`, `type`, `payload` (JSON crudo), `tenant_id` (nullable hasta resolverse), `status` (`received` | `processed` | `ignored` | `failed` | `unroutable`), `attempts`, `last_error`, `received_at`, `processed_at`.

**`webhook_endpoints`**
- `id`, `tenant_id`, `livemode`, `url` (`VARCHAR(2048)`), `description`, `enabled_events` (JSON; `["*"]` = todos), `secret` (cifrado; formato `whsec_<base64>`), `previous_secret` (cifrado, nullable), `previous_secret_expires_at`, `status` (`enabled` | `disabled_by_user` | `disabled_by_failures`), `failing_since`, `disabled_at`.

**`webhook_events`** (outbox: un evento de dominio por tenant)
- `id` (se expone como `evt_...` y se usa como `webhook-id`), `tenant_id`, `livemode`, `type`, `payload` (JSON: el cuerpo exacto que se enviará, congelado), `created_at`, `dispatched_at`.

**`webhook_deliveries`** (un registro por endpoint y por intento de entrega)
- `id`, `tenant_id`, `webhook_event_id`, `webhook_endpoint_id`, `attempt_number`, `status` (`pending` | `succeeded` | `failed` | `abandoned`), `scheduled_at`, `sent_at`, `response_status`, `response_body_excerpt` (máximo 2 KB, saneado), `duration_ms`, `error` (timeout, DNS, SSRF bloqueado, TLS...), `next_retry_at`.

**`validation_calls`** (log de validaciones previas; retención de 30 días)
- `id` (se envía como `webhook-id` del callback), `tenant_id`, `livemode`, `payment_link_id`, `payment_attempt_id` (nullable si el intento aún no existe en Stripe), `validation_endpoint_id`.
- `request_payload` (JSON congelado), `outcome` (`approved` | `rejected` | `failed`), `failure_kind` (`timeout` | `connection_error` | `http_error` | `invalid_response` | `blocked_destination`, nullable), `policy_applied` (`fail_closed` | `fail_open`, cuando `outcome = failed`), `final_decision` (`charge` | `block`).
- `reason_code`, `payer_message` (saneado), `response_status`, `response_body_excerpt` (máximo 2 KB), `duration_ms`, `created_at`.

**`link_open_events`** (opcional, para métricas de aperturas; retención de 90 días)
- `id`, `tenant_id`, `payment_link_id`, `opened_at`, `ip_hash` (hash con sal rotativa, no la IP en claro), `user_agent_family`.

### 7.7 Reportes

**`daily_payment_stats`** (rollup; se recalcula de forma idempotente por día)
- `tenant_id`, `livemode`, `stat_date` (`DATE` en la zona horaria del tenant), `currency` (moneda cobrada).
- `links_created`, `links_opened`, `links_paid`, `links_expired`, `links_canceled`.
- `payments_succeeded_count`, `payments_succeeded_amount_minor`, `payments_failed_count`.
- `refunds_count`, `refunds_amount_minor`, `disputes_opened_count`, `disputes_amount_minor`.
- `converted_payments_count` (pagos con FX).
- Llave primaria: `(tenant_id, livemode, stat_date, currency)`.

**`usage_reports`** (mensual, por tenant; inmutable una vez cerrado)
- `id`, `tenant_id`, `period` (`CHAR(7)`, `YYYY-MM`), `status` (`draft` | `closed`), `pricing_plan_id`, `lines` (JSON: por moneda, conteo y volumen bruto, comisión fija calculada, comisión porcentual calculada, topes aplicados, suscripción), `generated_at`, `closed_at`, `closed_by_admin_id`.
- Único: `(tenant_id, period)`.

### 7.8 Idempotencia de la API

**`idempotency_records`**
- `id`, `tenant_id`, `livemode`, `api_key_id`, `idempotency_key` (`ascii_bin`), `request_method`, `request_path`, `request_hash` (SHA-256 del cuerpo normalizado), `response_status`, `response_body` (JSON), `locked_until` (para requests concurrentes en curso), `created_at`, `expires_at` (24 horas).
- Único: `(tenant_id, livemode, idempotency_key)`.

---

## 8. Representación del dinero y redondeo

### 8.1 Monedas soportadas

Tabla o config `currencies`: `code`, `minor_unit_exponent`, `enabled`, `min_charge_minor`, `max_charge_minor`.

| Moneda | Exponente | Mínimo de cargo | Máximo de plataforma |
|---|---|---|---|
| USD | 2 | Verificar el mínimo vigente de Stripe (referencia histórica: 0.50 USD) | Configurable (por ejemplo, 10,000.00 USD) |
| MXN | 2 | Verificar el mínimo vigente de Stripe (referencia histórica: 10.00 MXN) | Configurable (por ejemplo, 200,000.00 MXN) |

> **Acción:** confirmar en la documentación de Stripe los mínimos actuales por moneda y cargarlos en la config. Los máximos son límites de riesgo de la plataforma (configurables también por tenant a futuro).

### 8.2 Parseo del monto en la API

- Se acepta **solo string**: `"amount": "150.50"`. Un número JSON (`150.5`) se rechaza con el error `amount_must_be_string`.
- Regex de validación según el exponente de la moneda (exponente 2): `^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$`.
- Sin separadores de miles, sin signo, sin notación científica, sin espacios.
- Conversión: `Money::of($amountString, $currency)->getMinorAmount()->toInt()` con `brick/money` (no hay float intermedio).
- Validar mínimo y máximo después de convertir.
- **Validación adicional con FX:** si el link está en USD y la conversión puede aplicar, se valida también que el monto convertido estimado (con el último tipo de cambio disponible) cumpla el mínimo de MXN. Si no, se rechaza con `amount_below_minimum_after_conversion`.

### 8.3 Representación en respuestas

- Siempre como string con el número exacto de decimales del exponente: `"amount": "150.50"`, `"currency": "MXN"`.
- Adicionalmente se incluye `amount_minor` (entero), para integradores que prefieren trabajar con unidades mínimas.

### 8.4 Redondeo

- **Regla única:** `RoundingMode::HALF_UP` al exponente de la moneda destino, aplicado **una sola vez**, al final del cálculo.
- Conversión FX: `converted = round_half_up(original_minor × effective_rate)`, calculado con `BigDecimal` de `brick/math` sobre el valor en unidades mínimas.
- `effective_rate = round_half_up(rate × (1 + markup_bps / 10000), 6 decimales)`. Se guarda en la cotización y **es el único valor usado para calcular el monto**; así, la cotización es reproducible.
- Comisiones en reportes: porcentaje = `round_half_up(amount_minor × bps / 10000)` por transacción; se suman ya redondeadas. Topes mínimo y máximo por transacción.

### 8.5 Pruebas obligatorias de dinero

- Tablas de casos (datasets de Pest) para parseo válido e inválido, conversión con casos límite de redondeo (.5 exacto), markup 0 y máximo, y montos mínimos y máximos.
- Prueba de propiedad: `minor → string → minor` es identidad para cualquier entero válido.

---

## 9. Máquinas de estado

Toda transición se ejecuta dentro de una transacción con `lockForUpdate()` sobre el link (y el intento, si aplica). Una transición inválida lanza `InvalidStateTransition` y se registra. Las transiciones se implementan en una clase por entidad (`PaymentLinkStateMachine`, `PaymentAttemptStateMachine`), con pruebas exhaustivas de la tabla de transiciones.

### 9.1 Payment Link

```
                ┌──────────── cancel (API/panel) ─────────────┐
                │                                             ▼
  (create) ─▶ active ──(intento entra a processing)──▶ processing ──(pago exitoso)──▶ paid
                │  ▲                                     │
                │  └──────(intento falla / se cancela)───┘
                │
                ├──(expires_at alcanzado, sin intento activo en processing)──▶ expired
                └──(cancel)──▶ canceled
```

| Desde | Hacia | Disparador | Reglas |
|---|---|---|---|
| — | `active` | `CreatePaymentLink` | Tenant `active` o `grace`; conexión de pasarela `active` con `charges_enabled`. |
| `active` | `processing` | Intento pasa a `processing` o `requires_action` | Bloquea nuevos intentos. |
| `processing` | `active` | Intento falla o se cancela | Si `expires_at` ya pasó → `expired` en su lugar. |
| `active`/`processing` | `paid` | Intento `succeeded` | Guarda `paid_at`. Cancela cualquier otro PaymentIntent residual del link. |
| `expired`/`canceled` | `paid` | Intento `succeeded` tardío | **Permitido (el pago gana).** Registra una anomalía en el audit log, emite el webhook `payment.succeeded` y marca el evento con `late_payment: true`. |
| `active` | `expired` | Job de expiración, o verificación perezosa al abrir la página | No expira si hay un intento en `processing` o `requires_action`: espera a su resolución. Cancela el PaymentIntent activo en Stripe. |
| `active` | `canceled` | API/panel | Cancela el PaymentIntent activo en Stripe (si el cancel falla porque el intent ya fue confirmado, se re-evalúa el estado). |
| `processing` | `canceled` | — | **No permitido:** hay un pago en curso. Responder `409 link_payment_in_progress`. |
| `paid` | cualquier otro | — | **Terminal.** Los reembolsos y disputas se reflejan en `refund_status` y `dispute_status`, no en `status`. |

**Expiración:** un job programado cada minuto (`ExpirePaymentLinksJob`) procesa en lotes los links `active` con `expires_at <= now()`. Además, la página de pago verifica la expiración al cargar (verificación perezosa), para no depender de la latencia del job.

### 9.2 Payment Attempt

Los estados internos son un espejo normalizado de los de Stripe (el adaptador hace el mapeo):

| Estado interno | Stripe PaymentIntent | ¿Terminal? |
|---|---|---|
| `requires_payment_method` | `requires_payment_method` | No |
| `requires_confirmation` | `requires_confirmation` | No |
| `requires_action` | `requires_action` (3DS) | No |
| `processing` | `processing` | No |
| `succeeded` | `succeeded` | Sí |
| `canceled` | `canceled` | Sí |
| `failed` | (derivado: `requires_payment_method` tras un error de pago con `last_payment_error`) | Sí, a nivel intento |

- **Decisión sobre rechazos:** en Stripe, un PaymentIntent cuyo cargo fue rechazado vuelve a `requires_payment_method` y **puede reutilizarse**. Por eso, `payment_attempts` es **1:1 con el PaymentIntent**: un rechazo **no** crea un registro nuevo ni marca el intento como terminal. El intento vuelve a `requires_payment_method`, se incrementa `failure_count` y el detalle de cada rechazo se guarda en la tabla ligera `payment_attempt_failures` (`id`, `tenant_id`, `payment_attempt_id`, `code`, `decline_code`, `message`, `card_country`, `card_brand`, `client_ip`, `created_at`).
- El estado `failed` se reserva para cuando el intento se cierra definitivamente **después** de al menos un rechazo (por ejemplo, porque el link expiró o se canceló y el PaymentIntent se cancela en Stripe). Si se cierra sin rechazos previos, queda `canceled`.
- El webhook `payment.failed` se emite **por cada rechazo** (con `failure_count`), porque al integrador le sirve saber que su cliente lo intentó; no significa que el link haya muerto.
- La restricción "un intento activo por link" (7.5) usa los estados no terminales.

---

## 10. Contrato de la API pública v1

> Mantener un `docs/api/openapi.yaml` (OpenAPI 3.1) como contrato formal. Esta sección es la especificación funcional.

### 10.1 Convenciones generales

- **Base URL:** `https://api.<dominio>/v1`.
- **Formato:** JSON UTF-8. `Content-Type: application/json` obligatorio en requests con cuerpo.
- **Versionado:** en la ruta (`/v1`). Los cambios compatibles (campos nuevos en respuestas, endpoints nuevos, eventos nuevos) no cambian la versión; los incompatibles requieren `/v2`. **Los integradores deben ignorar campos desconocidos** (documentarlo).
- **IDs:** con prefijo (ADR-020). Un ID con prefijo incorrecto → `404`.
- **Fechas:** ISO-8601 en UTC con `Z` (`2026-09-23T18:30:00Z`).
- **Header `Request-Id`** en todas las respuestas (ULID), también presente en los logs.
- **Paginación por cursor:** `?limit=` (1–100, default 20), `?starting_after=` y `?ending_before=` (IDs). Respuesta: `{ "object": "list", "data": [...], "has_more": true }`.
- **Rate limiting:** por API key (por ejemplo, 100 requests/minuto en live y 50 en test; configurable por tenant). Headers `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`. Al exceder, `429` con `Retry-After`.

### 10.2 Autenticación

- `Authorization: Bearer plk_live_<secreto>` o `plk_test_<secreto>`.
- Formato de la key: `plk_{mode}_{32 bytes aleatorios en base62}` (aproximadamente 43 caracteres de secreto).
- La key completa **se muestra una sola vez** al crearla. En BD se guarda `key_hash = SHA-256(key)` y `key_prefix` (para identificarla en el panel).
- **Hash:** SHA-256 es adecuado aquí porque la key tiene alta entropía (no es una contraseña). Comparación en tiempo constante (`hash_equals`).
- El prefijo determina `livemode`. Una key test **nunca** puede operar recursos live, y viceversa.
- **Scopes:** una key tiene una lista de permisos de API (subconjunto del catálogo, sección 17): `links:create`, `links:read`, `links:cancel`, `payments:read`, `refunds:create`, `refunds:read`, `events:read`.
- Una key revocada o expirada → `401 invalid_api_key` (sin revelar si existió).
- Si el tenant está `suspended` o `closed`, las operaciones de lectura se permiten y las de creación responden `403 tenant_suspended` (ADR-013). `closed` → solo lectura por un periodo de gracia y luego `401`.
- Se actualiza `last_used_at` y `last_used_ip` de forma diferida (como máximo una escritura por minuto por key), para no escribir en cada request.

### 10.3 Idempotencia

- Header `Idempotency-Key` (hasta 255 caracteres, `[A-Za-z0-9_\-:.]`), **recomendado en todos los POST y obligatorio en `POST /v1/payment_links` y `POST /v1/refunds`**. Si falta en esos endpoints → `400 idempotency_key_required`.
- Mismo key + mismo cuerpo dentro de 24 horas → se devuelve la respuesta original (mismo status y cuerpo) con el header `Idempotent-Replayed: true`.
- Mismo key + cuerpo distinto → `422 idempotency_key_reused`.
- Request concurrente con el mismo key aún en proceso → `409 idempotency_request_in_progress`.
- Implementación: registro en `idempotency_records` con bloqueo; se guarda la respuesta al finalizar. Las respuestas `5xx` **no** se guardan, para permitir reintentos.

### 10.4 Formato de error

```json
{
  "error": {
    "type": "invalid_request_error",
    "code": "currency_not_supported",
    "message": "La moneda 'EUR' no está soportada. Monedas permitidas: USD, MXN.",
    "param": "currency",
    "request_id": "01J8Z3..."
  }
}
```

**Tipos:** `invalid_request_error` (400/404/409/422), `authentication_error` (401), `permission_error` (403), `rate_limit_error` (429), `api_error` (500/503).

**Catálogo inicial de códigos** (mantenerlo en un enum `ApiErrorCode` y documentarlo):

| Código | HTTP | Descripción |
|---|---|---|
| `invalid_api_key` | 401 | Key ausente, inválida, revocada o expirada. |
| `insufficient_scope` | 403 | La key no tiene el permiso necesario. |
| `tenant_suspended` | 403 | El tenant no puede crear recursos. |
| `gateway_not_ready` | 409 | La cuenta de Stripe no está conectada o no tiene `charges_enabled`. |
| `parameter_missing` | 400 | Falta un parámetro obligatorio. |
| `parameter_invalid` | 400 | Formato inválido (genérico, con `param`). |
| `amount_must_be_string` | 400 | El monto se envió como número JSON. |
| `amount_invalid` | 400 | Formato de monto inválido para la moneda. |
| `amount_below_minimum` | 400 | Monto menor al mínimo permitido. |
| `amount_above_maximum` | 400 | Monto mayor al máximo permitido. |
| `amount_below_minimum_after_conversion` | 400 | El monto convertido a MXN no alcanza el mínimo. |
| `currency_not_supported` | 400 | Moneda no permitida. |
| `expiration_out_of_range` | 400 | Expiración menor al mínimo o mayor al máximo. |
| `fx_not_available` | 400 | Se pidió FX pero no aplica o no está habilitado. |
| `fx_rate_invalid` | 400 | Tipo fijo fuera de rango razonable (validación de cordura: ±30% del último FIX). |
| `metadata_invalid` | 400 | Metadata excede límites o contiene tipos no permitidos. |
| `return_url_not_allowed` | 400 | Dominio de `return_url` no permitido para el tenant. |
| `payer_field_invalid` | 400 | Configuración de campos del pagador inválida. |
| `validation_endpoint_not_configured` | 400 | Se pidió validación previa pero el tenant no tiene URL configurada para el modo. |
| `idempotency_key_required` | 400 | Falta `Idempotency-Key`. |
| `idempotency_key_reused` | 422 | Mismo key con cuerpo distinto. |
| `idempotency_request_in_progress` | 409 | Request concurrente con el mismo key. |
| `resource_not_found` | 404 | Recurso inexistente o de otro tenant/modo. |
| `link_not_cancelable` | 409 | El link ya está pagado, expirado o cancelado. |
| `link_payment_in_progress` | 409 | Hay un pago en curso. |
| `refund_exceeds_available` | 422 | El monto a reembolsar excede el saldo reembolsable. |
| `payment_not_refundable` | 409 | El pago no está en estado reembolsable. |
| `rate_limited` | 429 | Límite de requests excedido. |
| `gateway_error` | 502 | Error de la pasarela (sin detalles internos). |
| `internal_error` | 500 | Error inesperado. |

### 10.5 Recurso `payment_link`

**Crear:** `POST /v1/payment_links` (scope `links:create`)

Request:

```json
{
  "amount": "1500.00",
  "currency": "USD",
  "description": "Pedido #A-1029 — 2 artículos",
  "metadata": { "order_id": "A-1029", "customer_id": "C-77" },
  "client_reference_id": "A-1029",
  "expires_in_hours": 72,
  "fx": { "mode": "banxico_fix" },
  "payer_fields": { "email": "required", "phone": "optional" },
  "return_url": "https://tienda.ejemplo.com/gracias?order=A-1029",
  "locale": "es",
  "pre_payment_validation": true
}
```

| Campo | Tipo | Obligatorio | Reglas |
|---|---|---|---|
| `amount` | string | Sí | Sección 8.2. |
| `currency` | string | Sí | `USD` o `MXN` (mayúsculas; se normaliza si llega en minúsculas). |
| `description` | string | Sí | 1–500 caracteres; se muestra al pagador; se escapa al renderizar (texto plano, sin HTML). |
| `metadata` | objeto | No | Máximo 20 llaves; llaves de 1–40 caracteres `[A-Za-z0-9_\-]`; valores string de hasta 500 caracteres; sin objetos anidados. **No se muestra al pagador.** Documentar que no debe contener PII ni secretos. |
| `client_reference_id` | string | No | Hasta 200 caracteres. |
| `expires_in_hours` / `expires_at` | int / string | No (mutuamente excluyentes) | Default de la configuración del tenant; mínimo 15 minutos; máximo según la configuración del tenant, nunca más de 90 días. |
| `fx.mode` | string | No | `none`, `banxico_fix` o `fixed`. Si se omite, se usa la configuración del tenant. Solo aplica a links en USD. `fixed` requiere `fx.rate`. |
| `fx.rate` | string | Condicional | Decimal con hasta 6 decimales, > 0; validación de cordura contra el último FIX. |
| `payer_fields` | objeto | No | Override por campo: `hidden`, `optional` o `required` (sección 19). |
| `return_url` | string | No | HTTPS obligatorio en live; el host debe estar en `allowed_return_domains` del tenant; máximo 2048 caracteres. Evita open redirects. |
| `locale` | string | No | `es` o `en`; default del tenant. |
| `pre_payment_validation` | boolean | No | Activa la validación previa al cobro para este link (15.8). Default: `enabled_by_default` del tenant. Requiere URL de validación configurada para el modo. |

Validaciones de negocio al crear:
1. Tenant en estado `active` o `grace`.
2. Conexión de pasarela del modo correspondiente en `active` con `charges_enabled = true`.
3. Si `fx.mode != none` y la moneda es `MXN` → `fx_not_available` (no hay conversión que hacer).
4. Si `fx.mode != none` pero la conversión no está habilitada en el tenant → `fx_not_available`.

Response `201`:

```json
{
  "id": "plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S",
  "object": "payment_link",
  "livemode": true,
  "status": "active",
  "url": "https://pay.<dominio>/l/Xk29fLq...",
  "amount": "1500.00",
  "amount_minor": 150000,
  "currency": "USD",
  "description": "Pedido #A-1029 — 2 artículos",
  "metadata": { "order_id": "A-1029", "customer_id": "C-77" },
  "client_reference_id": "A-1029",
  "fx": { "mode": "banxico_fix", "rate": null },
  "payer_fields": { "email": "required", "full_name": "optional", "phone": "optional" },
  "return_url": "https://tienda.ejemplo.com/gracias?order=A-1029",
  "locale": "es",
  "pre_payment_validation": true,
  "expires_at": "2026-09-26T18:30:00Z",
  "paid_at": null,
  "canceled_at": null,
  "refund_status": "none",
  "dispute_status": "none",
  "open_count": 0,
  "first_opened_at": null,
  "payment": null,
  "created_at": "2026-09-23T18:30:00Z"
}
```

- Cuando el link está pagado, `payment` contiene el objeto de pago resumido (10.6).

**Consultar:** `GET /v1/payment_links/{id}` (scope `links:read`).

**Listar:** `GET /v1/payment_links` (scope `links:read`). Filtros: `status`, `currency`, `client_reference_id`, `created[gte]`, `created[lte]`.

**Cancelar:** `POST /v1/payment_links/{id}/cancel` (scope `links:cancel`). Cuerpo opcional: `{ "reason": "texto hasta 500" }`. Idempotente por naturaleza: cancelar un link ya cancelado devuelve `200` con el link; cancelar un link pagado o expirado → `409 link_not_cancelable`.

> **No existe** un endpoint para editar el monto o la moneda de un link. Si cambian, se cancela y se crea uno nuevo. Esto simplifica la auditoría y evita condiciones de carrera con pagos en curso.

### 10.6 Recurso `payment`

`GET /v1/payments/{id}` y `GET /v1/payments` (scope `payments:read`). Filtros: `status`, `payment_link`, `created[gte|lte]`.

```json
{
  "id": "pay_01J8Z4...",
  "object": "payment",
  "livemode": true,
  "payment_link": "plink_01J8Z3...",
  "status": "succeeded",
  "amount": "26137.50",
  "amount_minor": 2613750,
  "currency": "MXN",
  "original_amount": "1500.00",
  "original_currency": "USD",
  "fx": {
    "applied": true,
    "source": "banxico_fix",
    "rate": "17.250000",
    "rate_date": "2026-09-22",
    "markup_bps": 100,
    "effective_rate": "17.422500"
  },
  "card": { "brand": "visa", "last4": "4242", "country": "MX" },
  "payer": { "email": "cliente@ejemplo.com", "full_name": "Ana López" },
  "pre_validation": { "outcome": "approved", "policy_applied": null },
  "amount_refunded": "0.00",
  "refund_status": "none",
  "dispute_status": "none",
  "failure": null,
  "succeeded_at": "2026-09-24T02:11:09Z",
  "created_at": "2026-09-24T02:10:40Z"
}
```

- `payer` solo incluye los campos recolectados; si la PII fue purgada por retención, se devuelve `null` y `payer_purged: true`.
- **Nunca** se exponen IDs de Stripe en la API pública (ADR-019). En el panel sí, para soporte.

### 10.7 Recurso `refund`

`POST /v1/refunds` (scope `refunds:create`, `Idempotency-Key` obligatorio):

```json
{ "payment": "pay_01J8Z4...", "amount": "500.00", "reason": "requested_by_customer" }
```

- `amount` opcional (si se omite, es un reembolso total del saldo disponible); se expresa **en la moneda cobrada** (si hubo FX, en MXN).
- Respuesta `201` con `status: "pending"`. El estado final llega por webhook (`refund.succeeded` / `refund.failed`) o consultando `GET /v1/refunds/{id}`.
- `GET /v1/refunds` (filtros: `payment`, `status`).

### 10.8 Recurso `event` (para fetch-back)

`GET /v1/events/{id}` y `GET /v1/events` (scope `events:read`; filtros: `type`, `created[gte|lte]`). Devuelve el mismo cuerpo que se envió o enviará por webhook. Retención: 30 días.

### 10.9 Endpoints que NO existen en v1 (a propósito)

- Gestión de webhook endpoints, API keys, usuarios o branding vía API: **solo en el panel**, porque son operaciones sensibles que requieren un usuario autenticado con 2FA.
- Edición de links.
- Creación directa de pagos sin link.

---

## 11. Página de pago pública (checkout)

### 11.1 URL y token

- `https://pay.<dominio>/l/{public_token}`.
- `public_token`: 32 bytes aleatorios de `random_bytes()`, codificados en base62 (aproximadamente 43 caracteres). **No** se deriva del ID del link. Único, con collation binaria.
- La URL es secreta en la práctica: quien la tenga puede ver la descripción y pagar. Documentarlo para el tenant (no publicar links en lugares públicos si contienen información sensible en la descripción).

### 11.2 Estados de la página

| Estado del link | Qué ve el pagador |
|---|---|
| `active` | Página de pago completa. |
| `processing` | "Tu pago se está procesando" con consulta periódica del estado (polling cada 3 segundos, máximo 2 minutos, luego mensaje de revisar más tarde). |
| `paid` | "Este cobro ya fue pagado" (sin datos del pagador ni del pago; solo la descripción y la fecha). Si hay `return_url`, se ofrece un botón para volver al sitio del comercio. |
| `expired` | "Este enlace de pago expiró. Contacta a {display_name}" (y `support_email` si existe). |
| `canceled` | "Este enlace de pago ya no está disponible." |
| `active`, rechazado por la validación del comercio | Mensaje del comercio (`payer_message`) o el genérico; si `cancel_link = true`, a partir de ese momento se ve como `canceled`. |
| Token inexistente | `404` genérico, idéntico en tiempo y contenido para cualquier token inválido (sin filtrar información). |

Los estados `paid`, `expired` y `canceled` responden HTTP `200` con la vista informativa (un `410` confunde a algunos navegadores y usuarios); el token inválido responde `404`.

### 11.3 Contenido de la página `active`

- Logo, colores y nombre visible del tenant (sección 18).
- Descripción del cobro (texto escapado).
- Total en la moneda del link.
- **Leyenda FX** (solo si el link es USD, el tenant tiene la conversión activa y la cuenta conectada es MX): "Si pagas con una tarjeta emitida en México, se cobrarán **$X MXN** (tipo de cambio: Y, Banxico FIX del DD/MM/AAAA{, incluye ajuste del comercio})". El monto proviene de la cotización vigente (13.4).
- Campos del pagador configurados (sección 19).
- Payment Element de Stripe (solo tarjeta).
- Botón "Pagar {monto}".
- Enlace al aviso de privacidad del tenant (obligatorio si se recolectan datos del pagador) y texto "Pago procesado de forma segura por Stripe".
- Idioma según `locale` del link.
- **Nunca** se muestra `metadata` ni `client_reference_id`.

### 11.4 Flujo técnico del pago

```
Pagador                   Checkout (nuestro backend)                    Stripe
   │  GET /l/{token}                 │                                     │
   │────────────────────────────────▶│ resolver link, verificar estado     │
   │                                 │ registrar apertura (debounce)       │
   │                                 │ crear/recuperar cotización FX       │
   │◀────────── HTML + config ───────│ (si aplica)                         │
   │  Stripe.js(pk, {stripeAccount}) │   (con api_key: pk del comercio,    │
   │                                 │    sin stripeAccount; ver 12.4.1)     │
   │  elements deferred: mode=payment│                                     │
   │  amount/currency del link       │                                     │
   │  clic "Pagar"                   │                                     │
   │  createConfirmationToken() ─────┼────────────────────────────────────▶│
   │◀────────────────── ctoken_... ──┼─────────────────────────────────────│
   │  POST /l/{token}/attempts       │                                     │
   │  {confirmation_token, payer}    │                                     │
   │────────────────────────────────▶│ validar campos del pagador          │
   │                                 │ bloquear link (FOR UPDATE)          │
   │                                 │ recuperar ConfirmationToken ────────▶│
   │                                 │◀── card.country, brand ─────────────│
   │                                 │ evaluar regla FX (13.2)             │
   │                                 │                                     │
   │     ┌── SI requiere conversión y aún no fue confirmada por el pagador:│
   │◀────┤  respuesta {status: "requires_currency_confirmation",           │
   │     │   quote: {...}} (NO se crea ni confirma cargo)                  │
   │     │  UI muestra pantalla de confirmación en MXN                     │
   │     │  POST /l/{token}/attempts {confirmation_token, quote_id,        │
   │     │   currency_confirmed: true}                                     │
   │     └────────────────────────────▶ validar cotización vigente         │
   │                                 │                                     │
   │                                 │ ── SI el link tiene                 │
   │                                 │    pre_payment_validation:          │
   │                                 │    (fuera del bloqueo de fila)      │
   │                                 │    POST firmado a la URL del tenant ──▶ Sistema del tenant
   │                                 │    (timeout 5 s)  ◀── approve/reject ── │
   │     ┌── reject o fallo con fail_closed:                               │
   │◀────┤  {status: "rejected_by_merchant", message}  (NO se cobra)       │
   │     └──                         │                                     │
   │                                 │ re-bloquear link y re-verificar     │
   │                                 │ estado (pudo cambiar en 5 s)        │
   │                                 │                                     │
   │                                 │ crear o actualizar PaymentIntent ──▶│
   │                                 │ (monto/moneda final, metadata,      │
   │                                 │  idempotency_key) y confirmar con   │
   │                                 │  confirmation_token                 │
   │◀────── {status, client_secret?} │◀────────────────────────────────────│
   │  si requires_action: stripe.handleNextAction(client_secret) (3DS)     │
   │  redirección a /l/{token}/complete  → consulta estado                 │
   │                                 │  (la confirmación definitiva llega  │
   │                                 │   por webhook; la página consulta)  │
```

**Detalles importantes:**
- **Validación previa (ADR-024, sección 15.8):** se ejecuta **después** de los rate limits, Turnstile y la confirmación FX (para que los atacantes de card testing no saturen el servidor del tenant y para que el tenant reciba el monto final), y **antes** de crear o confirmar el cobro. La llamada HTTP al tenant **nunca** se hace mientras se mantiene un bloqueo de fila o una transacción abierta: se libera el bloqueo, se llama, y luego se vuelve a bloquear el link y se re-verifica que siga `active` y sin otro intento en curso.
- **Inicialización de Stripe.js según el método de conexión** (vía `CheckoutClientConfig`, 12.1): con `platform_onboarding` y `oauth`, publishable key de la plataforma + `stripeAccount: 'acct_...'`; con `api_key`, **publishable key del comercio** y sin `stripeAccount`. En todos los casos, el ConfirmationToken y el PaymentIntent viven en la cuenta del tenant.
- **Elements en modo deferred** con la moneda y el monto del link. Si el backend responde que se requiere conversión y Stripe exige consistencia entre Elements y el PaymentIntent, el frontend ejecuta `elements.update({ currency: 'mxn', amount })` y regenera el ConfirmationToken antes de reenviar. **Spike técnico obligatorio en la fase 4** para validar el comportamiento exacto (sección 27).
- **PaymentIntent único por link** (ADR-006): si existe uno activo en `requires_payment_method` o `requires_confirmation`, se **actualiza** (monto y moneda) en lugar de crear otro; la actualización de moneda está permitida antes de la confirmación. La restricción única de BD (7.5) respalda esta regla.
- **Idempotency key hacia Stripe:** `create_pi:{link_id}:{n}` para la creación y `confirm:{attempt_id}:{ctoken_id}` para la confirmación.
- La página de "completado" **no** marca el pago como exitoso por sí misma: consulta el estado. El backend, al recibir el retorno del cliente, puede hacer un `retrieve` del PaymentIntent para acelerar la actualización (además del webhook).
- **Métodos de pago:** `payment_method_types: ['card']`, sin wallets (ADR-009, ADR-018).
- **Datos que se envían a Stripe:** `receipt_email` solo si el tenant activó `send_stripe_receipts` y el email fue recolectado; `description` (la del link, truncada); `statement_descriptor_suffix` (opcional, configurable por tenant, a futuro); `metadata` de Stripe **solo con nuestros IDs** (`paylink_tenant_id`, `paylink_link_id`, `paylink_attempt_id`), **no** la metadata del integrador (para evitar filtrar datos y por los límites de Stripe).

### 11.5 Endpoints internos del checkout

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/l/{token}` | Renderiza la página. |
| POST | `/l/{token}/attempts` | Inicia o confirma el intento (flujo 11.4). |
| GET | `/l/{token}/status` | Polling de estado (JSON mínimo: `status`, `return_url` si está pagado). |
| GET | `/l/{token}/complete` | Página de retorno tras 3DS / confirmación. |

Estos endpoints usan CSRF (sesión anónima con cookie `SameSite=Lax`), rate limiting y el token del link. No aceptan API keys.

### 11.6 Registro de aperturas y evento `payment_link.opened`

- Cada `GET` válido sobre un link `active` incrementa `open_count`, actualiza `last_opened_at` y fija `first_opened_at` la primera vez.
- **Debounce del webhook:** `payment_link.opened` se emite en la **primera apertura** y luego como máximo **una vez cada 30 minutos** por link. El payload incluye `open_count` y `first_open: true|false`.
- Se excluyen bots conocidos por user-agent (previsualizadores de enlaces de WhatsApp, Slack, etc.) del conteo y del webhook, porque los previsualizadores generan aperturas falsas. Mantener una lista configurable.

### 11.7 Protección contra card testing y abuso (obligatorio)

Una página pública que acepta tarjetas y permite reintentos es un objetivo clásico de card testing. Defensas:

1. **Rate limit por link:** máximo 5 intentos de confirmación por link cada 15 minutos; al superarlo, se bloquea el link temporalmente 30 minutos y se registra.
2. **Rate limit por IP:** máximo 10 intentos de confirmación por IP cada hora, sumando todos los links.
3. **Cloudflare Turnstile** obligatorio a partir del 2.º intento fallido en el mismo link o la misma sesión (verificación en el servidor antes de llamar a Stripe).
4. **Bloqueo automático:** si un link acumula 10 rechazos, pasa a bloqueo prolongado (24 horas) y se notifica al tenant; el tenant puede desbloquearlo desde el panel.
5. **Radar de Stripe** activo (por defecto en la cuenta conectada); los rechazos de Radar se registran con su código.
6. **Alertas** a superadmins ante picos de rechazos por tenant (sección 24).
7. **No revelar** el motivo exacto de un rechazo al pagador (mensajes genéricos tipo "La tarjeta fue rechazada, intenta con otra o contacta a tu banco"); el detalle queda en el panel del tenant.

### 11.8 Cabeceras de seguridad del checkout

- `Content-Security-Policy` estricta: `default-src 'self'`; `script-src 'self' https://js.stripe.com https://challenges.cloudflare.com` (con nonce para los scripts inline); `frame-src https://js.stripe.com https://hooks.stripe.com https://challenges.cloudflare.com`; `connect-src 'self' https://api.stripe.com`; `img-src 'self' data:` (más el origen de los logos); `style-src 'self' 'nonce-...'`; `frame-ancestors 'none'`. Verificar la lista vigente de dominios de Stripe.js en su documentación de CSP.
- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`.
- `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` (el token va en la URL; no debe filtrarse en el header Referer hacia terceros).
- `X-Robots-Tag: noindex, nofollow` y `<meta name="robots" content="noindex">`.
- `Cache-Control: no-store` en la página de pago y en las respuestas de estado.

---

## 12. Integración con Stripe Connect

### 12.1 Puerto `PaymentGateway`

```php
interface PaymentGateway
{
    public function provider(): GatewayProvider;

    /** Estado de la cuenta (la conexión en sí es específica de cada proveedor; ver nota) */
    public function retrieveAccount(GatewayConnection $connection): ConnectedAccountData;

    /** Configuración que necesita el frontend del checkout (publishable key, cuenta, etc.) */
    public function clientConfig(GatewayConnection $connection): CheckoutClientConfig;

    /** Pagos */
    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview; // país, marca
    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment;
    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey): ProviderPayment;
    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment;
    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment;

    /** Reembolsos */
    public function refund(GatewayConnection $connection, RefundRequest $request): ProviderRefund;
    public function retrieveRefund(GatewayConnection $connection, string $providerRefundId): ProviderRefund;

    /** Webhooks */
    public function parseWebhook(string $rawBody, array $headers): ProviderWebhookEvent; // verifica firma adentro
}
```

- `ProviderPayment` contiene: `providerPaymentId`, `status` (enum **interno** normalizado), `amountMinor`, `currency`, `clientAction` (`none` | `client_secret` para Stripe | `redirect_url` para pasarelas de redirección), `cardPreview`, `failure`.
- `GatewayFactory::for(GatewayProvider $provider): PaymentGateway`, implementada con un `match`.
- **Nota sobre la conexión:** los flujos de conexión (Account Links, OAuth, API key) son **específicos del proveedor** y no forman parte del puerto. Viven en `Modules/Gateways/Stripe/Connection/` (`PlatformOnboardingFlow`, `OAuthFlow`, `ApiKeyFlow`) y los usa el panel. Otra pasarela futura tendrá sus propios flujos. El puerto solo cubre lo que el dominio de pagos necesita.
- `CheckoutClientConfig` devuelve lo que el frontend necesita para inicializar el SDK de la pasarela; para Stripe: `publishableKey` y `stripeAccount` (este último `null` con `api_key`). La vista del checkout no sabe qué método de conexión se usa.
- **Pruebas:** `FakePaymentGateway` implementa la interfaz para las pruebas de dominio; `StripeGateway` se prueba con pruebas de contrato contra el modo test de Stripe (sección 26).

### 12.2 Configuración de la plataforma en Stripe

- Cuenta de plataforma Connect con perfil de plataforma completado.
- **Llaves separadas** test y live en variables de entorno (`STRIPE_TEST_SECRET`, `STRIPE_LIVE_SECRET`, `STRIPE_TEST_PUBLISHABLE`, `STRIPE_LIVE_PUBLISHABLE`, `STRIPE_TEST_CONNECT_WEBHOOK_SECRET`, `STRIPE_LIVE_CONNECT_WEBHOOK_SECRET`). El cliente de Stripe se construye según el `livemode` del contexto.
- `STRIPE_TEST_CONNECT_CLIENT_ID` y `STRIPE_LIVE_CONNECT_CLIENT_ID` (`ca_...`) para el método `oauth`.
- `GATEWAY_CREDENTIALS_KEY` (32 bytes aleatorios, base64) para cifrar las restricted keys del método `api_key`; distinta de `APP_KEY` y respaldada por separado.
- **Versión de la API de Stripe fijada** en el cliente (`stripe_version`) y en los endpoints de webhook. Las actualizaciones de versión son un cambio deliberado, probado en staging.
- Branding de la plataforma en la configuración de Connect (se muestra durante el onboarding).

### 12.3 Conexión del tenant con Stripe (ADR-004: tres métodos)

La pantalla "Conectar Stripe" del panel (permiso `gateway:manage`, con re-autenticación) ofrece los métodos habilitados por configuración de plataforma (`config/paylink.php` → `gateways.stripe.connection_methods`), en este orden y con esta presentación:

1. **"Crear o conectar con Stripe (recomendado)"** → `platform_onboarding`.
2. **"Ya tengo cuenta de Stripe: conectar con OAuth"** → `oauth` (solo si está habilitado y disponible para la plataforma).
3. **"Configuración avanzada: usar mis llaves de API"** → `api_key`, con advertencias visibles.

#### 12.3.1 Método `platform_onboarding` (Accounts API + Account Links)

1. Si no existe `gateway_connection` para el modo: se crea la cuenta conectada vía Accounts API con controller properties equivalentes a "Standard" (el comercio paga las comisiones de Stripe y las pérdidas no recaen en la plataforma). Se guarda `provider_account_id`, `connection_method = platform_onboarding` y `status = onboarding`.
2. Se genera un Account Link (`type=account_onboarding`) con `return_url` y `refresh_url` del panel y se redirige al usuario.
3. Al volver (`return_url`), se consulta la cuenta y se actualizan `charges_enabled`, `payouts_enabled`, `requirements` y `country`. **El retorno no garantiza que el onboarding esté completo;** la fuente de verdad es la consulta y el evento `account.updated`.
4. `refresh_url`: genera un nuevo Account Link (los Account Links expiran y son de un solo uso).
5. El panel muestra el estado y los requisitos pendientes en lenguaje claro, con un botón para continuar el onboarding.
6. `status = active` cuando `charges_enabled = true`.

#### 12.3.2 Método `oauth` (OAuth Connect)

> **Precondición:** confirmar en el dashboard de Connect que OAuth está disponible para nuestra plataforma (sección 29). Stripe no lo recomienda para plataformas nuevas; si no está disponible, el método queda deshabilitado por configuración.

1. Se genera un parámetro `state` aleatorio (32 bytes), ligado a la sesión del usuario, al tenant y al modo, y guardado con expiración de 10 minutos (protección CSRF).
2. Redirección a `https://connect.stripe.com/oauth/authorize` con `response_type=code`, `client_id` de la plataforma (del modo correspondiente), `scope=read_write`, `state` y `redirect_uri` registrada.
3. En el callback: validar `state` (existencia, coincidencia con la sesión y el tenant, no expirado, **un solo uso**). Si viene `error` (el usuario negó el acceso), mostrar un mensaje y registrar.
4. Intercambiar el `code` en el endpoint de token de Stripe **una sola vez** (según la referencia de OAuth de Stripe, consumir un código más de una vez revoca la conexión). Proteger el intercambio con un bloqueo por `state` para evitar dobles envíos del navegador.
5. Guardar `stripe_user_id` como `provider_account_id`, `connection_method = oauth` y `oauth_scope`. **No almacenar el `access_token` ni el `refresh_token`:** todas las llamadas usan la llave de la plataforma + `Stripe-Account`.
6. Consultar la cuenta y sincronizar `country`, `charges_enabled`, etc. Si la cuenta ya está vinculada a otro tenant (restricción única), rechazar y revocar la autorización recién otorgada.
7. Desconexión iniciada por nosotros: llamada al endpoint de deauthorize de Stripe. Desconexión iniciada por el comercio: evento `account.application.deauthorized`.

#### 12.3.3 Método `api_key` (llaves del comercio) — controles obligatorios

**Datos que captura el formulario:**
- **Restricted key** (`rk_test_...` / `rk_live_...`): campo tipo password, sin autocompletar, nunca se vuelve a mostrar completa.
- **Publishable key** (`pk_test_...` / `pk_live_...`): **obligatoria**, porque Stripe.js necesita la publishable key de la cuenta donde se crearán el ConfirmationToken y el PaymentIntent. Con este método no existe relación de plataforma, así que no se puede usar la `pk` de la plataforma con `stripeAccount`.
- Casilla de aceptación del aviso de riesgo (texto definido por la plataforma: la plataforma almacenará una credencial de la cuenta de Stripe del comercio; el comercio es responsable de configurar la llave con permisos mínimos y de rotarla si sospecha compromiso).

**Validaciones al conectar (en este orden; si una falla, no se guarda nada):**
1. **Formato y tipo:** la llave secreta debe empezar con `rk_`. **Cualquier `sk_` se rechaza** con un mensaje que explica cómo crear una restricted key y qué permisos darle.
2. **Modo consistente:** el modo de la `rk_` y el de la `pk_` deben coincidir entre sí y con el modo seleccionado en el panel (test o live).
3. **Llave válida y cuenta identificada:** `GET /v1/account` con la `rk_`. Se obtiene `provider_account_id`, `country` y el estado de cobro. Si la restricted key no tiene permiso de lectura de la cuenta, se rechaza (el permiso es necesario para la regla FX, que depende del país).
4. **La `pk_` pertenece a la misma cuenta:** crear con la `pk_` un objeto inocuo que la `rk_` pueda consultar, y verificar que se encuentra. **El mecanismo exacto se define en el spike de la fase 2C.** Si no hay un mecanismo confiable del lado del servidor, se valida desde el navegador del usuario del panel durante la conexión (Stripe.js con la `pk_` crea el objeto y el backend lo consulta con la `rk_`).
5. **Permisos suficientes:** llamadas de prueba de solo lectura (listas con `limit=1`) sobre los recursos necesarios. Permisos requeridos (lista definitiva a confirmar en el spike, según los nombres vigentes en el dashboard de Stripe):
   - Escritura: PaymentIntents, Refunds, Webhook Endpoints.
   - Lectura: Account (datos básicos), ConfirmationTokens / PaymentMethods, Charges, Disputes, Events.
6. **Permisos excesivos (advertencia):** si las pruebas detectan acceso a recursos que no necesitamos y que son peligrosos (por ejemplo, payouts, transferencias, cuentas bancarias externas), se muestra una advertencia fuerte recomendando crear una llave más restringida. En live se exige una confirmación adicional para continuar.
7. **Unicidad:** el fingerprint de la llave y el `provider_account_id` no pueden estar vinculados a otro tenant.

**Creación del webhook en la cuenta del comercio:**
- Con la `rk_`, crear un webhook endpoint (`POST /v1/webhook_endpoints`) apuntando a `https://api.<dominio>/webhooks/stripe/direct/{connection_id}`, con la **versión de la API fijada** y solo los eventos de la sección 14.3 que aplican (no incluye los eventos `account.application.*`).
- Guardar `provider_webhook_endpoint_id` y el `secret` devuelto (cifrado). El secret solo se devuelve al crear el endpoint.
- Si la creación falla, la conexión no se activa.
- Al desconectar, se elimina el endpoint en la cuenta del comercio (si la llave sigue siendo válida) y se destruyen las credenciales almacenadas (sobrescribir con `NULL`; queda registro en el audit log sin el valor).

**Almacenamiento:**
- La `rk_` se cifra con un encriptador dedicado (`GatewayCredentialsEncrypter`), cuya llave es `GATEWAY_CREDENTIALS_KEY`, **distinta de `APP_KEY`**, guardada fuera del repositorio y respaldada por separado. Se versiona (`credentials_key_version`) para poder rotarla con un comando de re-cifrado.
- Recomendado a futuro: mover esta llave a un gestor de secretos (HashiCorp Vault o similar) en lugar de un archivo `.env` en el mismo servidor.
- La `rk_` descifrada solo vive en memoria durante la llamada; nunca se registra, nunca se serializa en jobs (el job recibe el `connection_id` y descifra al ejecutar) y nunca se envía a Sentry.
- En el panel se muestra enmascarada (`rk_live_…a1b2`). **No existe la opción de revelarla;** si el comercio la necesita, la obtiene de su dashboard de Stripe.

**Salud de la conexión:**
- Job diario `CheckApiKeyConnectionsJob`: `GET /v1/account` con cada `rk_` activa, actualiza `last_health_check_*` y sincroniza `country` y `charges_enabled`.
- Cualquier error de autenticación o permisos (en el job o en un cobro real) → `status = invalid_credentials`, creación de links bloqueada (`gateway_not_ready`), correo inmediato a los usuarios con `gateway:manage` y a los owners, y aviso en el checkout de los links activos ("El comercio no puede recibir pagos temporalmente").
- **Rotación de llave por el comercio:** el panel permite "Actualizar llaves" (mismas validaciones). Si la cuenta es la misma, se conserva la conexión y se re-crea o actualiza el webhook endpoint.

**Diferencias funcionales documentadas para el tenant:**
- No hay onboarding de Stripe asistido: el comercio debe tener su cuenta ya verificada y lista para cobrar.
- No hay notificación automática si revoca la llave; se detecta en el health check o en el siguiente cobro.

#### 12.3.4 Reglas comunes a los tres métodos

- `status = restricted` cuando `charges_enabled` pasa a `false` (requisitos vencidos): se notifica al tenant y la creación de links se bloquea (`gateway_not_ready`). Los links existentes muestran un aviso en el checkout.
- Desconexión (por cualquier vía): `status = disconnected`, se bloquea la creación de links, se cancelan los links activos (webhook `payment_link.canceled` con `reason: gateway_disconnected`) y se notifica.
- **Cambio de método** (por ejemplo, de `api_key` a `platform_onboarding`): requiere desconectar primero. Si hay links activos, el panel lo advierte y ofrece cancelarlos. **No se migran links entre conexiones:** un PaymentIntent vive en una cuenta concreta.
- Los intentos de pago guardan el `provider_account_id` y el `connection_id` con el que se crearon, para que los reembolsos y las consultas usen siempre la misma cuenta, aunque luego cambie la conexión.

**Verificar antes de implementar** (sección 29): disponibilidad de OAuth para la plataforma, combinaciones válidas de controller properties, países soportados como cuentas conectadas según el país de nuestra plataforma, costos de Connect para la plataforma y nombres vigentes de los permisos de restricted keys.

### 12.4 Creación del PaymentIntent (direct charge)

Parámetros clave. El contexto de autenticación lo resuelve la `StripeClientFactory` (12.4.1): llave de la plataforma + header `Stripe-Account` en `platform_onboarding` y `oauth`; restricted key del comercio sin header en `api_key`.

- `amount`, `currency` (finales, posiblemente convertidos).
- `payment_method_types: ['card']`.
- `confirmation_method`/flujo compatible con ConfirmationToken (confirmación del lado del servidor).
- `description`: la descripción del link (truncada al límite de Stripe).
- `metadata`: `paylink_tenant_id`, `paylink_link_id`, `paylink_attempt_id`, `paylink_livemode`.
- `receipt_email`: condicional (11.4).
- **Sin** `application_fee_amount` (ADR-011).
- Header `Idempotency-Key`.

### 12.4.1 `StripeClientFactory`: contexto de llamada según el método de conexión

```php
final class StripeClientFactory
{
    public function __construct(
        private readonly PlatformStripeKeys $platformKeys,
        private readonly GatewayCredentialsEncrypter $encrypter,
    ) {}

    public function for(GatewayConnection $connection): StripeCallContext
    {
        return match ($connection->connection_method) {
            ConnectionMethod::PlatformOnboarding,
            ConnectionMethod::OAuth => StripeCallContext::connect(
                client: $this->platformClient($connection->livemode),
                stripeAccount: $connection->provider_account_id,
                publishableKey: $this->platformKeys->publishable($connection->livemode),
            ),
            ConnectionMethod::ApiKey => StripeCallContext::direct(
                client: new StripeClient([
                    'api_key' => $this->encrypter->decrypt($connection->credentials_secret, $connection->credentials_key_version),
                    'stripe_version' => config('services.stripe.api_version'),
                ]),
                publishableKey: $connection->credentials_publishable,
            ),
        };
    }
}
```

- `StripeCallContext` expone un método para construir las opciones de cada request (`['stripe_account' => ...]` solo en el contexto Connect, más la `idempotency_key`). **Todas** las llamadas de `StripeGateway` pasan por él; ninguna construye un `StripeClient` por su cuenta.
- El contexto no se cachea entre requests ni se serializa en jobs.
- Pruebas: una prueba por método verifica que las opciones generadas son correctas (con y sin `stripe_account`) y que la restricted key nunca aparece en logs ni en excepciones serializadas.

### 12.5 Reconciliación

- **Job cada 15 minutos:** busca intentos no terminales con más de 10 minutos de antigüedad y consulta su estado en Stripe; corrige discrepancias y emite los eventos faltantes.
- **Job diario (madrugada, hora del servidor):** para cada conexión activa, lista los PaymentIntents, reembolsos y disputas de las últimas 48 horas en Stripe y compara contra la BD. Registra las discrepancias en un reporte y alerta si hay alguna.
- Las correcciones pasan por las **mismas Actions** que los webhooks (sin lógica duplicada).

### 12.6 Manejo de errores de Stripe

| Error | Manejo |
|---|---|
| `CardException` (rechazo) | Registrar en `payment_attempt_failures`, mensaje genérico al pagador y conteo para anti card testing. |
| `RateLimitException` | Reintento con backoff exponencial en jobs; en el checkout, un reintento breve y luego un error amable. |
| `InvalidRequestException` | Bug nuestro o dato inválido: log de error con contexto y alerta; no reintentar. |
| `AuthenticationException` / `PermissionException` | Con métodos Connect: cuenta desconectada o llave de plataforma inválida, así que se marca la conexión para revisión y se alerta. Con `api_key`: `status = invalid_credentials` inmediato y notificación (12.3.3). |
| `ApiConnectionException` / timeout | Reintentar **con la misma idempotency key**; nunca con una nueva (evita cobros dobles). |
| `ApiErrorException` 5xx | Igual que el timeout. |

---

## 13. Conversión de moneda (FX) y Banxico

### 13.1 Configuración

- **Tenant:** `fx.conversion_enabled` (default `false`), `fx.default_mode` (`banxico_fix` | `fixed`), `fx.markup_bps` (0–1000), `fx.quote_validity_minutes` (default 30; rango 5–120).
- **Link:** `fx.mode` y `fx.rate` opcionales al crear (10.5); sin override, se usa la configuración del tenant.
- Solo aplica a links en `USD` de tenants cuya conexión tenga `country = MX`.

### 13.2 Regla de decisión (implementada en `Fx\Services\ConversionPolicy`)

```
requiere_conversion =
      link.currency == 'USD'
  AND connection.country == 'MX'
  AND card.country == 'MX'

si requiere_conversion:
    si link.fx_mode == 'none' o tenant.fx.conversion_enabled == false:
        → BLOQUEAR: no crear cargo; mostrar al pagador
          "Este comercio no puede cobrar este monto en USD a tarjetas mexicanas.
           Contacta a {display_name}."; registrar el evento conversion_unavailable
          (visible en el panel del tenant, sin webhook en el MVP)
    si no:
        → CONVERTIR con la cotización vigente, previa confirmación del pagador (ADR-009)
si no:
    → cobrar en la moneda del link sin conversión
```

- La tabla de verdad completa (combinaciones de moneda del link, país de la cuenta, país de la tarjeta, conversión activa o no y modo) se implementa como un dataset de Pest.

### 13.3 Obtención del tipo de cambio de Banxico

- **Fuente:** API SIE de Banxico, **serie FIX** (identificador de la serie: verificar en la documentación de Banxico; históricamente `SF43718`).
- **Token:** variable de entorno `BANXICO_SIE_TOKEN`; nunca en el repositorio.
- **Job programado** `FetchBanxicoFixJob` en días hábiles, a las 12:30, 13:30 y 17:00 hora de Ciudad de México (el FIX se publica alrededor del mediodía), más una ejecución de respaldo a las 09:00 del día siguiente. Es idempotente: si el registro de la fecha ya existe, no hace nada.
- Se guarda en `exchange_rates` con el payload crudo.
- **Validación de cordura:** si el nuevo valor difiere más de ±10% del anterior, no se usa automáticamente; se guarda marcado como `requires_review` y se alerta a los superadmins.
- **Fines de semana y días festivos:** no hay publicación; se usa el último FIX disponible (comportamiento esperado).
- **Antigüedad máxima:** si el FIX más reciente tiene más de **4 días naturales**, la conversión `banxico_fix` queda **bloqueada** (el pagador ve el mensaje de conversión no disponible) y se alerta a los superadmins. Nunca se cobra con un tipo de cambio obsoleto.
- Timeouts cortos (10 segundos), reintentos con backoff y registro de fallos.
- **El checkout nunca llama a Banxico:** solo lee `exchange_rates`.

### 13.4 Cotizaciones

- Se crea una cotización (`fx_quotes`) **al abrir la página** cuando aplica la leyenda FX, o al detectar la tarjeta si no existía una vigente.
- Vigencia: `quote_validity_minutes`. Mientras esté vigente, la página muestra exactamente `converted_amount`.
- Al confirmar el pagador:
  - Si la cotización sigue vigente → se cobra `converted_amount` de esa cotización.
  - Si venció → se crea una nueva. **Si el monto cambió, se vuelve a pedir confirmación**; si no cambió (mismo FIX), se procede.
- **Modo `fixed`:** la cotización usa `fx_fixed_rate` del link (el markup del tenant **no** se aplica sobre un tipo fijo, porque el integrador ya decidió el tipo; documentarlo) y no expira mientras el link esté vigente.
- Fórmulas y redondeo: sección 8.4.

### 13.5 Pantalla de confirmación de conversión

Contenido mínimo:
- "Tu tarjeta fue emitida en México. Este cobro se realizará en pesos mexicanos."
- Monto original: `1,500.00 USD`.
- **Monto a cobrar: `$26,137.50 MXN`** (destacado).
- Tipo de cambio aplicado: `17.4225` (fuente: Banxico FIX del 22/09/2026 + ajuste del comercio de 1.00%) o "tipo de cambio definido por el comercio".
- Botones: "Pagar $26,137.50 MXN" y "Cancelar".
- Si hay markup, se debe indicar que existe un ajuste del comercio (transparencia).

### 13.6 Datos que llegan al integrador

- Los objetos `payment` y los webhooks incluyen `amount`/`currency` (cobrado), `original_amount`/`original_currency` y el bloque `fx` completo (10.6). Documentar que **la conciliación del integrador debe usar ambos**.

### 13.7 Impacto en reportes

- Las métricas se agrupan por **moneda cobrada**. Adicionalmente se reporta el conteo de pagos convertidos y el volumen original en USD de esos pagos.

---

## 14. Webhooks entrantes de Stripe

### 14.1 Endpoints

Hay dos tipos de endpoint, según el método de conexión (ADR-004):

| Endpoint | Métodos | Secret | Resolución del tenant |
|---|---|---|---|
| `POST /webhooks/stripe/connect/{mode}` | `platform_onboarding`, `oauth` | Uno por modo (variable de entorno) | `event.account` → `gateway_connections` |
| `POST /webhooks/stripe/direct/{connection_id}` | `api_key` | Uno por conexión (`provider_webhook_secret`, cifrado) | `connection_id` de la URL; se verifica además que `event.account` (si viene) o la cuenta de la conexión coincidan |

Detalle del endpoint Connect:

- `POST https://api.<dominio>/webhooks/stripe/connect/{mode}` (`mode` = `test` | `live`), configurado en Stripe como **endpoint de Connect** (recibe los eventos de las cuentas conectadas), un endpoint por modo con su propio signing secret.
- No se requiere endpoint de eventos de la cuenta de plataforma en el MVP (no hay Billing; ADR-011). Si se agrega, será un endpoint separado con otro secret.
- Excluido del middleware de CSRF, de sesión y de autenticación de API; con rate limiting generoso.

### 14.2 Procesamiento

1. Leer el **cuerpo crudo** (`$request->getContent()`) y el header `Stripe-Signature`.
2. Verificar la firma con `\Stripe\Webhook::constructEvent()` usando el secret correspondiente (del modo, para el endpoint Connect; de la conexión, para el endpoint direct) y la tolerancia por defecto (5 minutos). En el endpoint direct, si el `connection_id` no existe o la conexión está desconectada, responder `404` sin revelar detalles (y, si está desconectada, intentar eliminar el endpoint remoto en segundo plano). Si falla → `400` y métrica `stripe_webhook_signature_failures` (una alerta si sube).
3. `INSERT` en `provider_events` con `provider_event_id` único. Si ya existe (duplicado) → responder `200` sin reprocesar.
4. Despachar `ProcessProviderEventJob` y responder **`200` inmediatamente**. Stripe espera una respuesta rápida; ningún trabajo pesado va en el request.
5. El job resuelve el tenant (Connect: `event.account` → `gateway_connections`; direct: la conexión de la URL), establece el `TenantContext` y despacha al handler según `type`.
6. **El handler vuelve a consultar el objeto en Stripe** (`retrievePayment`, `retrieveRefund`, `retrieveAccount`) y aplica el estado actual, no el del payload. Esto resuelve el desorden y la llegada de eventos viejos (ADR-017).
7. Aplica la transición mediante la Action correspondiente (en transacción, con bloqueo) y marca el evento como `processed`. Si falla, reintentos del job (5, con backoff) y luego `failed` con alerta.
8. Eventos de tipos no manejados → `ignored`.

### 14.3 Eventos manejados

| Evento de Stripe | Acción |
|---|---|
| `payment_intent.succeeded` | Intento → `succeeded`; link → `paid`; outbox `payment.succeeded` y `payment_link.paid`; rollup. |
| `payment_intent.payment_failed` | Registrar el rechazo; outbox `payment.failed`; contadores anti card testing. |
| `payment_intent.processing` | Intento → `processing`; link → `processing`. |
| `payment_intent.requires_action` | Intento → `requires_action`. |
| `payment_intent.canceled` | Intento → `canceled`/`failed` (9.2); link → `active` o `expired` según corresponda. |
| `charge.refunded` | Sincronizar reembolsos (incluidos los creados desde el dashboard de Stripe, `origin = provider_dashboard`). |
| `refund.created` / `refund.updated` / `refund.failed` | Actualizar el estado del reembolso; outbox `refund.*`; recalcular `amount_refunded_minor` y `refund_status`. |
| `charge.dispute.created` | Crear la disputa; `dispute_status = open`; outbox `dispute.created`; notificar al tenant. |
| `charge.dispute.updated` | Actualizar el estado. |
| `charge.dispute.closed` | Estado final (`won`/`lost`/`warning_closed`); outbox `dispute.closed`. |
| `account.updated` | Sincronizar `charges_enabled`, `payouts_enabled`, `requirements` y `status` de la conexión; notificar si pasa a `restricted`. |
| `account.application.deauthorized` | Conexión → `disconnected` (12.3.4). Solo aplica a métodos Connect. |

**Con `api_key`**, el endpoint creado en la cuenta del comercio se suscribe solo a los eventos `payment_intent.*`, `charge.refunded`, `refund.*`, `charge.dispute.*` y `account.updated`. No existe `account.application.deauthorized`; la revocación se detecta por errores de autenticación y el health check (12.3.3).

### 14.4 Eventos que no pertenecen a un intento nuestro

Si un `payment_intent.*` no tiene `metadata.paylink_attempt_id` o no existe en nuestra BD (por ejemplo, el comercio cobró por su cuenta desde su dashboard u otra integración), se marca `ignored` con motivo `foreign_object`. Esto será **frecuente con `api_key` y `oauth`**, porque son cuentas que el comercio también usa para otras ventas; el filtro debe ser barato (verificar la metadata del payload antes de cualquier consulta a Stripe). Para no llenar la tabla, los eventos `foreign_object` se guardan con el payload reducido y se purgan a los 7 días. **No** se crean pagos a partir de objetos que no originó la plataforma.

---

## 15. Webhooks salientes hacia clientes y validación previa al cobro

### 15.1 Configuración (solo desde el panel)

- Un tenant puede registrar hasta **5 endpoints por modo** (configurable).
- Por endpoint: URL, descripción, eventos suscritos (lista o `*`) y estado.
- El secret se genera al crear el endpoint: `whsec_` + base64 de 32 bytes aleatorios. Se muestra completo **una vez** (con opción de "revelar" posterior solo con re-autenticación). Se guarda **cifrado** (no hasheado, porque se necesita para firmar), con el cast `encrypted` de Laravel.
- Botón **"Enviar evento de prueba"** (evento `ping`).
- Vista de **log de entregas** por endpoint con filtro por estado y botón de **reenvío manual**.

### 15.2 Catálogo de eventos

| Evento | Cuándo |
|---|---|
| `payment_link.created` | Link creado (API o panel). |
| `payment_link.opened` | Primera apertura y luego como máximo una vez cada 30 minutos (11.6). |
| `payment_link.paid` | Link pagado (incluye el objeto `payment`). |
| `payment_link.expired` | Link expirado. |
| `payment_link.canceled` | Link cancelado (incluye `reason`). |
| `payment.processing` | Pago en procesamiento (raro con tarjetas; se deja por compatibilidad futura). |
| `payment.succeeded` | Pago exitoso (incluye `late_payment` si ocurrió tras la expiración o cancelación). |
| `payment.failed` | Cada rechazo (incluye `failure_count` y un código genérico). |
| `refund.created` | Reembolso solicitado. |
| `refund.succeeded` | Reembolso completado. |
| `refund.failed` | Reembolso fallido. |
| `dispute.created` | Disputa abierta. |
| `dispute.closed` | Disputa cerrada (incluye el resultado). |
| `ping` | Evento de prueba. |

> La **validación previa al cobro** no aparece en este catálogo porque no es un webhook (15.8). Los eventos `payment.succeeded` y `payment.failed` incluyen el bloque `pre_validation` con el resultado de la validación, cuando aplica.

### 15.3 Formato del payload

```json
{
  "id": "evt_01J8Z5...",
  "type": "payment.succeeded",
  "api_version": "v1",
  "livemode": true,
  "created_at": "2026-09-24T02:11:10Z",
  "data": {
    "object": { "...": "objeto completo, igual que en la API (10.5 / 10.6 / 10.7)" }
  }
}
```

- El cuerpo se **congela** al crear el evento (se guarda en `webhook_events.payload`) y se envía idéntico en todos los reintentos.
- Documentar el patrón **fetch-back**: antes de liberar mercancía, el integrador puede confirmar con `GET /v1/payments/{id}` o `GET /v1/events/{id}`.

### 15.4 Outbox (entrega confiable)

1. Dentro de la **misma transacción** que el cambio de estado (por ejemplo, link → `paid`), se inserta el registro en `webhook_events` y un registro `pending` en `webhook_deliveries` por cada endpoint suscrito.
2. Después del commit (`afterCommit`), se despacha `DeliverWebhookJob` por cada entrega.
3. Si el proceso muere entre el commit y el despacho, un **job barredor cada minuto** despacha las entregas `pending` cuyo `scheduled_at` ya pasó y no tienen un job en curso.
4. Garantía: **al menos una vez** (at-least-once). El receptor debe deduplicar por `webhook-id`. **No se garantiza el orden;** el receptor debe usar `created_at` y el estado del objeto.

### 15.5 Firma (Standard Webhooks, HMAC-SHA256)

Headers enviados:

```
webhook-id: evt_01J8Z5...
webhook-timestamp: 1758679870
webhook-signature: v1,<base64(HMAC-SHA256(secret_bytes, "{webhook-id}.{webhook-timestamp}.{body}"))>
content-type: application/json
user-agent: PayLink-Webhooks/1.0
```

- `secret_bytes` = base64-decode de la parte posterior a `whsec_`.
- Se firma el **cuerpo crudo exacto** que se envía.
- **Rotación de secret sin downtime:** al rotar, el secret anterior sigue siendo válido 24 horas; durante ese periodo, `webhook-signature` contiene **ambas firmas** separadas por espacio (`v1,<firma_nueva> v1,<firma_vieja>`), como permite la especificación.
- Documentar la verificación del lado receptor: recalcular la firma, comparar en **tiempo constante**, rechazar timestamps fuera de ±5 minutos y deduplicar por `webhook-id`. Proveer ejemplos en PHP, Node y Python en la documentación para integradores.

Implementación de referencia:

```php
final class WebhookSigner
{
    public function sign(string $secret, string $webhookId, int $timestamp, string $body): string
    {
        $secretBytes = base64_decode(Str::after($secret, 'whsec_'), strict: true);
        $signature = base64_encode(hash_hmac('sha256', "{$webhookId}.{$timestamp}.{$body}", $secretBytes, binary: true));

        return "v1,{$signature}";
    }
}
```

### 15.6 Política de entrega y reintentos

- Método `POST`, timeout de conexión de 5 segundos y timeout total de 10 segundos.
- **Éxito:** cualquier `2xx`. Todo lo demás (incluidos `3xx`) es fallo.
- **No se siguen redirecciones.**
- Se lee como máximo 2 KB de la respuesta (se guarda un extracto saneado).
- **Calendario de reintentos** (a partir del primer fallo): inmediato → 5 s → 5 min → 30 min → 2 h → 5 h → 10 h → 10 h (≈ 27 horas en total, 8 intentos). Luego la entrega queda `abandoned`.
- Si un endpoint acumula **fallos continuos durante 5 días** (`failing_since`), se deshabilita (`disabled_by_failures`) y se notifica por correo a los usuarios del tenant con permiso `webhooks:manage`.
- Reenvío manual desde el panel: crea un nuevo intento de entrega del mismo evento (mismo `webhook-id`).
- Retención del log de entregas: 30 días.

### 15.7 Protección SSRF (obligatoria)

Aplica a los webhooks **y** a la URL de validación previa (15.8). Al **registrar** la URL y en **cada entrega o llamada**:

1. Esquema `https` obligatorio en live (en test se permite `http` solo si la configuración de la plataforma lo habilita; por defecto también `https`).
2. Sin credenciales en la URL (`user:pass@`), puerto `443` (o una lista blanca: 443 y 8443), longitud máxima de 2048 caracteres, y host que no sea una IP literal.
3. **Resolución DNS y validación de TODAS las IPs resueltas** contra rangos bloqueados:
   - IPv4: `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`, `192.0.0.0/24`, `192.168.0.0/16`, `198.18.0.0/15`, `224.0.0.0/4`, `240.0.0.0/4`, `255.255.255.255/32`.
   - IPv6: `::1/128`, `::/128`, `fc00::/7`, `fe80::/10`, `::ffff:0:0/96` (validar la IPv4 mapeada), `64:ff9b::/96`.
   - Hostnames como `localhost`, `*.local`, `*.internal` y los dominios propios de la plataforma.
4. **Anti DNS rebinding:** conectarse a la IP validada (fijar la resolución en el cliente HTTP, por ejemplo con la opción `CURLOPT_RESOLVE` / `resolve` de Guzzle) en lugar de volver a resolver.
5. Sin redirecciones (punto 15.6).
6. Idealmente, las entregas salen por un **proxy de egreso** o una IP dedicada, para que los tenants puedan hacer allowlist; documentar las IPs de salida.
7. Un fallo por SSRF se registra en la entrega con `error = blocked_destination` y no se reintenta.

### 15.8 Validación previa al cobro (callback síncrono; ADR-024)

> **No es un webhook.** Comparte con los webhooks la firma HMAC y la protección SSRF, pero su semántica es distinta: es síncrono, espera una decisión y su resultado determina si se cobra.

#### 15.8.1 Configuración (panel, permiso `webhooks:manage`)

- Una URL por modo (`validation_endpoints`), con secret propio (`whsec_...`, distinto del de los webhooks), rotación con doble firma durante 24 horas, igual que en 15.5.
- `enabled_by_default`: si los links nuevos usan la validación cuando la API no indica nada.
- `failure_policy`: `fail_closed` (default) o `fail_open`. El panel explica las consecuencias de cada una con un texto claro.
- Botón **"Probar validación"**: envía un payload de ejemplo (`test: true`) y muestra la respuesta, la latencia y si el formato es válido.
- Por link: `pre_payment_validation: true|false` en `POST /v1/payment_links` (si se omite, se usa `enabled_by_default`). Si se pide `true` y el tenant no tiene URL configurada para ese modo → `400 validation_endpoint_not_configured`.

#### 15.8.2 Momento de la llamada

En el flujo de 11.4, en este orden: campos del pagador validados → rate limits y Turnstile superados → ConfirmationToken inspeccionado → confirmación FX (si aplica) → **validación previa** → creación o confirmación del cobro. Se llama **una vez por intento de confirmación del pagador**: si una tarjeta es rechazada y el pagador reintenta, se vuelve a validar (el `attempt_number` lo indica).

#### 15.8.3 Request

`POST` a la URL configurada, con los headers de Standard Webhooks (`webhook-id` = ID de `validation_calls`, `webhook-timestamp`, `webhook-signature`) y además `x-paylink-kind: pre_payment_validation`.

```json
{
  "id": "val_01J8Z6...",
  "type": "payment.pre_validation",
  "livemode": true,
  "created_at": "2026-09-24T02:10:55Z",
  "attempt_number": 1,
  "data": {
    "payment_link": {
      "id": "plink_01J8Z3...",
      "client_reference_id": "A-1029",
      "metadata": { "order_id": "A-1029", "customer_id": "C-77" },
      "description": "Pedido #A-1029 — 2 artículos",
      "amount": "1500.00",
      "currency": "USD",
      "expires_at": "2026-09-26T18:30:00Z"
    },
    "charge": {
      "amount": "26137.50",
      "currency": "MXN",
      "fx": { "applied": true, "source": "banxico_fix", "effective_rate": "17.422500" }
    },
    "card": { "brand": "visa", "country": "MX" },
    "payer": { "email": "cliente@ejemplo.com", "full_name": "Ana López" }
  }
}
```

- `charge` refleja **el monto y la moneda que se van a cobrar** (ya convertidos si aplica), para que el tenant valide el monto final.
- Nunca se envían datos de tarjeta más allá de la marca y el país.

#### 15.8.4 Response esperada

HTTP `200` con `Content-Type: application/json` y un cuerpo como:

```json
{ "decision": "approve" }
```

```json
{
  "decision": "reject",
  "reason_code": "out_of_stock",
  "payer_message": "Uno de los artículos ya no está disponible. Contacta a la tienda.",
  "cancel_link": false
}
```

| Campo | Reglas |
|---|---|
| `decision` | Obligatorio: `approve` o `reject`. Cualquier otro valor = respuesta inválida. |
| `reason_code` | Opcional; `[a-z0-9_]`, máximo 64 caracteres. Se guarda y se muestra en el panel. |
| `payer_message` | Opcional; texto plano, máximo 200 caracteres, se escapa al renderizar. Si no viene, el pagador ve un mensaje genérico ("El comercio no pudo confirmar esta operación. Contacta a {display_name}."). |
| `cancel_link` | Opcional (default `false`). Si es `true` y la decisión es `reject`, el link se cancela (`reason: rejected_by_merchant`) y se emite `payment_link.canceled`. |

- Cuerpo máximo leído: 4 KB. Cuerpos mayores = respuesta inválida.
- Se ignoran campos desconocidos (compatibilidad futura).
- La autenticidad de la respuesta la da la conexión TLS verificada hacia la URL del tenant (nosotros iniciamos la conexión). La firma de la respuesta queda como posible mejora futura.

#### 15.8.5 Tiempos y fallos

- Timeout de conexión de 2 segundos y **timeout total de 5 segundos**. El pagador ve un indicador de "Verificando tu pedido…".
- Un solo reintento inmediato **solo** ante un fallo de conexión (antes de enviar el cuerpo); nunca tras un timeout de lectura (el tenant pudo haber procesado la solicitud).
- Son **fallos**: timeout, error de conexión o TLS, HTTP distinto de `200` (incluidos `3xx`, porque no se siguen redirecciones), JSON inválido, `decision` ausente o inválida, y destino bloqueado por SSRF.
- Ante un fallo se aplica `failure_policy`:
  - `fail_closed`: no se cobra; el pagador ve el mensaje genérico y puede reintentar más tarde.
  - `fail_open`: se cobra; el `validation_calls` registra `final_decision = charge` con `policy_applied = fail_open`, y el webhook `payment.succeeded` incluye `pre_validation: { "outcome": "failed", "policy_applied": "fail_open" }`, para que el integrador pueda revisar.
- **Circuito de protección:** tras 10 fallos consecutivos, se notifica por correo a los usuarios con `webhooks:manage` (como máximo una vez por hora). La validación **no** se desactiva automáticamente (sería un cambio de seguridad silencioso), pero el panel muestra una alerta.

#### 15.8.6 Idempotencia y garantías para el integrador

- Cada llamada tiene un `id` único; el integrador puede recibir varias validaciones para el mismo link (reintentos del pagador).
- **Una aprobación no garantiza el cobro:** después de `approve`, la tarjeta puede ser rechazada o requerir 3DS y abandonarse. El integrador debe confirmar la venta solo con `payment.succeeded` (o consultando la API).
- Si el integrador reserva stock al aprobar, debe liberarlo si no recibe `payment.succeeded` en un tiempo razonable (recomendación: 15 minutos). Documentarlo con un ejemplo.
- Cuando hay reembolsos, disputas o expiraciones, los webhooks asíncronos habituales siguen aplicando.

#### 15.8.7 Visibilidad

- El detalle del link en el panel muestra cada validación (decisión, motivo, latencia, política aplicada).
- Métricas por tenant: tasa de rechazos por validación, tasa de fallos y latencia p95 (alertas en la sección 24).

---

## 16. Reembolsos y disputas

### 16.1 Reembolsos

- **Dónde:** panel (permiso `payments:refund`, con re-autenticación) y API (scope `refunds:create`).
- **Reglas:**
  - Solo pagos `succeeded`.
  - `amount` ≤ `amount_minor - amount_refunded_minor - suma de reembolsos pending`. Se valida **con bloqueo** sobre el intento para evitar dos reembolsos simultáneos que excedan el saldo.
  - La moneda es la cobrada.
  - Límite opcional de monto por reembolso según el rol (configurable a futuro; en el MVP, solo el permiso).
- **Flujo:** crear el registro `refunds` (`pending`) → llamar a Stripe con `Idempotency-Key = refund:{refund_id}` → guardar `provider_refund_id` → el estado final llega por webhook (`refund.updated`) o por reconciliación.
- **Reembolsos creados fuera de la plataforma** (dashboard de Stripe): se importan vía `charge.refunded` / `refund.created` con `origin = provider_dashboard`.
- `refund_status` del link y del pago: `none` → `partial` → `full`.
- **Comisiones de la plataforma:** no se revierten (ADR-012).
- Audit log en cada reembolso (quién, cuánto, motivo).
- Webhooks: `refund.created`, `refund.succeeded`, `refund.failed`.

### 16.2 Disputas

- Con direct charges, **la disputa la gestiona el comercio** desde su dashboard de Stripe; la plataforma solo **registra y notifica**.
- Se muestran en el panel con el monto, el motivo, el estado y la fecha límite de evidencia (`evidence_due_by`), con un enlace al dashboard de Stripe del comercio.
- Notificación por correo a los usuarios con `payments:read` + rol owner/admin al abrirse una disputa.
- La gestión de evidencia desde nuestro panel queda fuera del MVP.

---

## 17. Usuarios, roles y permisos (RBAC)

### 17.1 Catálogo de permisos del tenant

| Permiso | Descripción |
|---|---|
| `links:create` | Crear links (panel). |
| `links:read` | Ver links. |
| `links:cancel` | Cancelar links. |
| `payments:read` | Ver pagos, detalles del pagador, reembolsos y disputas. |
| `payments:refund` | Crear reembolsos. **Sensible.** |
| `metrics:read` | Ver métricas y dashboards. |
| `reports:export` | Exportar CSV. |
| `api_keys:manage` | Crear y revocar API keys. **Sensible.** |
| `webhooks:manage` | Gestionar endpoints de webhooks, la URL de validación previa y su política de fallo, y ver los logs de entregas y validaciones. **Sensible.** |
| `gateway:manage` | Conectar, desconectar y ver la configuración de Stripe (cualquiera de los tres métodos, incluido registrar llaves de API). **Muy sensible:** puede redirigir el dinero. |
| `settings:manage` | Branding, FX, campos del pagador, expiraciones y dominios de retorno. |
| `users:manage` | Invitar, desactivar usuarios y asignar roles. **Sensible.** |
| `audit:read` | Ver el log de auditoría del tenant. |

Los scopes de las API keys son un subconjunto: `links:create`, `links:read`, `links:cancel`, `payments:read`, `refunds:create` (equivale a `payments:refund`), `refunds:read`, `events:read`.

### 17.2 Roles de sistema (seeders; editables solo por el superadmin)

| Rol | Permisos |
|---|---|
| `owner` | Todos. Mínimo uno por tenant; no se puede eliminar ni degradar al último owner. |
| `admin` | Todos excepto `gateway:manage` y transferir la propiedad. |
| `integration_manager` | `api_keys:manage`, `webhooks:manage`, `gateway:manage`, `links:read`, `payments:read`. |
| `finance` | `links:read`, `payments:read`, `payments:refund`, `metrics:read`, `reports:export`. |
| `link_creator` | `links:create`, `links:read`, `links:cancel`. |
| `viewer` | `links:read`, `payments:read`, `metrics:read`. |

- Roles personalizados creados por el tenant: **fuera del MVP** (el modelo ya lo soporta).
- Un usuario puede tener varios roles; sus permisos son la unión.

### 17.3 Reglas de seguridad de acceso

- **2FA obligatorio** para cualquier usuario con permisos sensibles (`owner`, `admin`, `integration_manager`, `finance`) y para todos los superadmins. Recomendado para los demás.
- **Re-autenticación** (confirmar la contraseña o el código 2FA, válida 10 minutos) para: `gateway:manage`, crear o revocar API keys, crear, editar o revelar el secret de webhooks o de la validación previa, cambiar la política de fallo de la validación, registrar o actualizar llaves de API de Stripe, reembolsos, cambios de rol, cambios de FX y de dominios de retorno.
- **Notificación por correo** a todos los owners cuando: se conecta o desconecta Stripe, se crea una API key live, se agrega o cambia un endpoint de webhook, se asigna un rol sensible o se cambia la configuración de FX.
- **Invitaciones:** con token de un solo uso (hash en BD), expiración de 72 horas y rol predefinido.
- **Sesiones:** cookie `Secure`, `HttpOnly`, `SameSite=Lax`; expiración por inactividad de 2 horas; invalidación de las demás sesiones al cambiar la contraseña.
- **Contraseñas:** mínimo 12 caracteres, verificación contra contraseñas filtradas (regla `Password::uncompromised()` de Laravel) y rate limit en el login.
- **Autorización** con Policies de Laravel que verifican **permisos**; nunca `if ($user->hasRole('admin'))`.

### 17.4 Plataforma (superadmin)

- `platform_admins` con guard `platform`, en el subdominio `admin.`, con 2FA obligatorio y, recomendado, allowlist de IP o VPN.
- Roles: `superadmin` (todo) y `support_readonly` (solo lectura, sin ver PII completa, que se muestra enmascarada).
- **Impersonation** ("ver como tenant"): solo `superadmin`, con motivo obligatorio, sesión limitada a 30 minutos, **solo lectura** por defecto, banner visible y registro en el audit log del tenant y de la plataforma.

---

## 18. Branding / white label

- **Campos:** `display_name` (máximo 60 caracteres), logo, `primary_color`, `accent_color`, `background_style` (claro u oscuro), `support_email`, `privacy_notice_url`.
- **Logo:**
  - Formatos permitidos: PNG, JPEG y WebP. **SVG prohibido** (vector de XSS).
  - Validar el tipo real por *magic bytes*, no por extensión ni `Content-Type`.
  - Tamaño máximo de 1 MB y dimensiones máximas de 2000×2000.
  - **Re-codificar** la imagen (por ejemplo, con Intervention Image) a PNG/WebP de un tamaño normalizado (máximo 400×120), eliminando metadatos EXIF.
  - Nombre de archivo aleatorio; se sirve desde un disco público con `Content-Type` correcto, `X-Content-Type-Options: nosniff` y caché larga (nombre versionado).
- **Colores:** hex `#RRGGBB`; validar el **contraste WCAG AA (≥ 4.5:1)** del texto del botón sobre el color primario; si no cumple, se rechaza el color o se calcula automáticamente el color de texto (blanco o negro) que sí cumple.
- **Vista previa** en vivo en el panel.
- Los **dominios personalizados** por tenant (`pagos.cliente.com`) quedan fuera del MVP (requieren emitir certificados TLS por tenant).
- La página muestra discretamente "Procesado por Stripe"; el nombre de nuestra plataforma puede ocultarse (white label) según el plan (configurable por el superadmin: `show_platform_badge`).

---

## 19. Campos configurables del pagador

### 19.1 Catálogo (MVP)

| Campo | Tipo | Validación | Se envía a Stripe |
|---|---|---|---|
| `email` | email | RFC 5322 básico, máximo 254 caracteres, dominio con registro MX (opcional) | `receipt_email` (si está activado) y `billing_details.email` |
| `full_name` | texto | 2–120 caracteres | `billing_details.name` |
| `phone` | teléfono | E.164 (con selector de país), `libphonenumber` | `billing_details.phone` |
| `company_name` | texto | 2–120 caracteres | No |
| `billing_address` | compuesto: `country`, `state`, `city`, `postal_code`, `line1`, `line2` | País ISO-2; CP según el país (MX: 5 dígitos) | `billing_details.address` |
| `tax_id` | texto | Hasta 20 caracteres alfanuméricos (sin validar formato fiscal en el MVP) | No |
| `notes` | texto libre | Hasta 500 caracteres | No |

- Cada campo puede estar `hidden`, ser `optional` o ser `required`.
- **Configuración por tenant** (default en `settings.payer_fields`; si no está definido, `email: optional` y el resto `hidden`).
- **Override por link** vía API (`payer_fields`); la configuración efectiva se **congela** en el link al crearlo.
- **Prellenado** (fase futura): permitir que el integrador envíe valores iniciales al crear el link (`payer_prefill`).
- La tarjeta y el país de la tarjeta **no** son campos del catálogo: los maneja el Payment Element.

### 19.2 Protección de datos

- Almacenamiento en `payer_details.data`, **cifrado** (cast `encrypted:array`).
- **Retención:** por defecto 24 meses desde el pago (configurable por el superadmin por tenant: 6, 12, 24 o 60 meses). Un job diario purga los datos vencidos (`purge_after`), dejando intactos los registros financieros.
- **Minimización:** mostrar en el panel enmascarado por defecto para roles sin `payments:read`; exportaciones CSV solo con `reports:export`.
- **Rol legal:** el tenant es el **responsable** de los datos de sus pagadores y la plataforma es **encargada** del tratamiento. El checkout debe mostrar el aviso de privacidad del tenant. Revisar con asesoría legal el contrato de encargo y las obligaciones de la legislación mexicana de protección de datos personales (sección 29).
- Nunca se registran datos del pagador en logs ni en sistemas de error (scrubbing en Sentry).

---

## 20. Métricas y reportes

### 20.1 Rollups diarios

- `RebuildDailyStatsJob(tenant_id, livemode, date)`: recalcula **desde cero** el día indicado (idempotente: borra e inserta dentro de una transacción) a partir de las tablas fuente.
- **Disparadores:** (a) un listener tras eventos relevantes (pago exitoso, reembolso, etc.) encola el recálculo del día afectado, con debounce de 1 minuto por tenant y día; (b) un job nocturno recalcula los últimos 3 días de todos los tenants (corrige eventos tardíos).
- El día se calcula en la **zona horaria del tenant** (`tenants.timezone`). Un pago a las 23:30 de Ciudad de México cuenta para ese día, no para el siguiente en UTC.

### 20.2 Dashboard del tenant

- Selector de periodo: **día, semana, mes, año** y rango personalizado; selector de modo test/live.
- KPIs por **moneda** (nunca sumados entre monedas): volumen cobrado, número de pagos exitosos, ticket promedio, tasa de conversión (links pagados / links creados en el periodo), reembolsos, disputas, links expirados.
- Gráficas: serie temporal de volumen y conteo por día, semana o mes.
- Tabla de links con filtros (estado, moneda, fecha, referencia) y búsqueda por `client_reference_id`.
- Detalle del link: línea de tiempo (creado → aperturas → intentos y rechazos → pago → reembolsos o disputas), datos del pagador (según permisos), cotización FX si aplica, e IDs de Stripe (para soporte).
- Exportación CSV (permiso `reports:export`), generada en un job, con enlace de descarga firmado y temporal.
- Las semanas se definen como ISO (lunes a domingo); documentarlo en la UI.

### 20.3 Dashboard de plataforma (superadmin)

- Tenants por estado; onboarding pendiente; volumen global por moneda; tenants con errores de webhooks; tasa de rechazos por tenant (detección de card testing); estado de Banxico (último FIX, antigüedad); cola (profundidad y antigüedad del job más viejo); eventos `unroutable` o `failed`.

---

## 21. Cobro de la plataforma a sus clientes (externo), plan tarifario y estado del tenant

### 21.1 Planes tarifarios

- Tabla `tenant_pricing_plans` (7.1), versionada por vigencia. Cualquier combinación es válida: solo suscripción; solo fijo por transacción; solo porcentaje; fijo + porcentaje; cualquiera de ellos con suscripción; o todo en cero (tenant de cortesía).
- Topes opcionales por transacción (`fee_min_minor`, `fee_max_minor`).
- **Nunca se edita un plan vigente;** se crea una nueva versión con `effective_from` futuro o actual, y se cierra la anterior (`effective_to`).
- Solo el superadmin gestiona los planes; queda en el audit log.

### 21.2 Reporte de uso mensual

- `GenerateUsageReportJob(tenant, period)`: el día 1 de cada mes (zona horaria de la plataforma, `America/Mexico_City`) genera un borrador del mes anterior. Se puede regenerar mientras esté en `draft`.
- **Base del cálculo:** pagos `succeeded` en **livemode** dentro del periodo (por `succeeded_at` en la zona horaria de la plataforma), agrupados por **moneda cobrada**. No se descuentan reembolsos ni disputas (ADR-012).
- **Si el plan cambió a mitad de mes,** cada pago usa la versión vigente en su `succeeded_at`.
- **Comisión fija en una moneda distinta a la del pago** (por ejemplo, fijo de 1 USD y pago en MXN): se reporta como **cantidad de transacciones × comisión fija en su moneda original** (1 USD por transacción), sin convertir. La conversión, si se necesita, la decide la facturación externa. Documentarlo.
- Resultado: `lines` por moneda (conteo, volumen bruto, comisión fija total, comisión porcentual total, topes aplicados), más la suscripción del plan.
- **Cerrar** el reporte (superadmin) lo vuelve inmutable. Exportación CSV y PDF (PDF opcional).
- **IVA:** fuera del sistema (lo calcula la facturación externa del operador). El reporte muestra importes sin impuestos.

### 21.3 Estados del tenant y matriz de comportamiento

| Estado | Crear links (API/panel) | Links vigentes cobran | Panel | API lectura | Quién lo cambia |
|---|---|---|---|---|---|
| `pending_onboarding` | No (`gateway_not_ready`) | — | Sí (configuración) | Sí | Automático al registrar |
| `active` | Sí | Sí | Completo | Sí | Superadmin / automático al completar onboarding |
| `grace` | Sí (con aviso en el panel) | Sí | Completo + banner | Sí | Superadmin |
| `suspended` | **No** (`tenant_suspended`) | **Sí** (ADR-013) | **Solo lectura** + banner | Sí | Superadmin |
| `closed` | No | No (links activos cancelados con `reason: tenant_closed`, previo aviso) | Solo lectura 30 días, luego sin acceso | 30 días, luego `401` | Superadmin |

- Cada cambio de estado exige motivo, queda en el audit log y notifica a los owners del tenant.
- La transición a `closed` requiere una doble confirmación en el panel superadmin.

---

## 22. Notificaciones por correo

> Sin correos a pagadores (ADR-022). Todos los correos se envían vía cola, con plantillas en español (e inglés, a futuro), sin incluir datos sensibles (sin montos detallados de pagadores ni PII en el asunto).

| Evento | Destinatarios |
|---|---|
| Invitación de usuario | Invitado |
| Conexión o desconexión de Stripe; cuenta `restricted`; llaves de API inválidas (`invalid_credentials`); llave con permisos excesivos | Owners + usuarios con `gateway:manage` |
| Validación previa con fallos consecutivos (máximo 1 por hora); cambio de la política de fallo | Owners + `webhooks:manage` |
| API key live creada o revocada | Owners |
| Endpoint de webhook creado, modificado o deshabilitado por fallos | Owners + `webhooks:manage` |
| Disputa abierta | Owners + admins + `finance` |
| Link bloqueado por posible card testing | Owners + admins |
| Cambio de estado del tenant (grace/suspended/closed) | Owners |
| Asignación de rol sensible | Owners + el usuario afectado |
| **Alertas de plataforma:** Banxico desactualizado, cola atascada, scheduler caído, picos de rechazos, eventos `unroutable`, fallos de reconciliación | Superadmins |

---

## 23. Seguridad

### 23.1 Modelo de amenazas resumido

| Amenaza | Superficie | Mitigación principal |
|---|---|---|
| Fuga de datos entre tenants | API, panel, jobs | Sección 6 completa (fail-closed, FKs compuestas, CI, pruebas). |
| Robo o abuso de API keys | API | Hash, prefijos por modo, scopes, revocación, rate limit, `last_used`, alertas de uso anómalo. |
| Card testing | Checkout | Sección 11.7. |
| SSRF vía webhooks | Worker de webhooks | Sección 15.7. |
| Falsificación de webhooks entrantes | Endpoint Stripe | Verificación de firma + re-consulta del objeto (14.2). |
| Falsificación de webhooks salientes hacia el integrador | Integrador | HMAC + timestamp + fetch-back documentado (15.5). |
| Doble cobro | Checkout, API | Un PaymentIntent activo por link (BD + lógica), idempotency keys, bloqueos. |
| Doble reembolso | API, panel | Bloqueo + validación de saldo + idempotency keys. |
| Toma de cuenta de un usuario | Panel | 2FA, rate limit de login, contraseñas no comprometidas, re-autenticación en acciones sensibles, notificaciones. |
| **Robo de restricted keys de comercios** (método `api_key`) | BD, servidor, backups | Solo `rk_` (nunca `sk_`), validación de permisos, cifrado con `GATEWAY_CREDENTIALS_KEY` separada de `APP_KEY` y de los backups, sin revelado en el panel, nunca en logs ni jobs serializados, fingerprint único, health check y aceptación de riesgo registrada. |
| CSRF o reutilización del código en el callback OAuth | Panel | `state` aleatorio de un solo uso ligado a la sesión, intercambio del código una sola vez con bloqueo. |
| Servidor del tenant lento o caído en la validación previa | Checkout | Timeout de 5 s, política de fallo explícita, sin reintentos largos, llamada fuera de bloqueos de BD. |
| Uso de la validación previa como vector SSRF o de saturación | Worker/checkout | Mismas reglas SSRF que los webhooks; la validación ocurre después de los rate limits y Turnstile. |
| Redirección del dinero (cambiar la cuenta de Stripe) | Panel | `gateway:manage` muy restringido, re-autenticación, notificación a todos los owners y audit log. |
| XSS | Checkout, panel | Escapado por defecto de Blade, CSP estricta, sin SVG, descripción como texto plano. |
| Open redirect | `return_url` | Allowlist de dominios por tenant. |
| Enumeración | API, checkout | ULIDs, tokens de 256 bits, `404` uniformes. |
| Escalada a superadmin | Panel | Tabla y guard separados, subdominio propio, allowlist de IP. |
| Filtración de secretos | Repo, logs, BD | `.env` fuera del repo, secretos cifrados en BD, redacción en logs, escaneo de secretos en CI. |

### 23.2 Gestión de secretos (servidor propio)

- `.env` con permisos `600`, propiedad del usuario de la aplicación; **nunca** en el repositorio (con `.env.example` sin valores reales).
- `APP_KEY` respaldado de forma segura y separada de los backups de BD (sin él, los datos cifrados son irrecuperables). Rotación con `APP_PREVIOUS_KEYS` de Laravel.
- Secretos de terceros (Stripe, Banxico, Turnstile, SMTP) solo en `.env`.
- `GATEWAY_CREDENTIALS_KEY` respaldada **por separado** de `APP_KEY` y de los backups de BD: quien obtenga un backup no debe poder descifrar las restricted keys de los comercios. Comando `paylink:rotate-gateway-credentials-key` para re-cifrar con una nueva versión de la llave.
- Escaneo de secretos en CI (por ejemplo, gitleaks) y en pre-commit.
- Cifrado de campos en BD con el cifrador de Laravel: secrets de webhooks, secretos 2FA, PII del pagador.

### 23.3 Logging seguro

- Logs estructurados en JSON.
- **Redacción automática** (processor de Monolog) de: headers `Authorization`, `Stripe-Signature`, `webhook-signature`, cookies, campos `password`, `secret`, `token`, `key`, `email`, `phone`, `name`, `address`, `tax_id`, y cualquier `client_secret` de Stripe.
- No registrar cuerpos completos de requests del checkout ni de la API (solo metadatos: ruta, estado, duración, IDs).

### 23.4 Cabeceras y transporte

- TLS 1.2+ (preferente 1.3) en todos los dominios; HSTS con preload; redirección HTTP→HTTPS.
- CSP estricta en el checkout (11.8) y razonable en los paneles.
- Cookies con `Secure` y aisladas por subdominio.
- CORS: la API **no** habilita CORS para navegadores (las API keys son secretas y de servidor a servidor). Documentar que la API nunca debe llamarse desde el navegador del pagador.

### 23.5 Dependencias y código

- `composer audit` y `npm audit` en CI; Dependabot o Renovate.
- Larastan al nivel máximo viable, Pint y pruebas obligatorias en cada PR.
- Revisión de seguridad manual antes de la salida a producción (checklist de la sección 30).

---

## 24. Observabilidad y operación

### 24.1 Logs

- Contexto obligatorio en cada línea: `request_id`, `tenant_id`, `livemode`, `actor_type`, `actor_id` y, cuando aplique, `payment_link_id`, `payment_attempt_id`, `provider_event_id`, `webhook_event_id`, `job`.
- El `Request-Id` se propaga a los jobs despachados desde el request.
- Rotación y retención de logs en el servidor (por ejemplo, 30 días locales) y, opcionalmente, envío a un agregador (Loki, Graylog o similar).

### 24.2 Errores

- Sentry o GlitchTip con scrubbing de PII y secretos, `environment` y `release` configurados, y agrupación por tenant como tag (sin datos sensibles).

### 24.3 Métricas y alertas

| Métrica | Alerta sugerida |
|---|---|
| Profundidad de la cola y antigüedad del job pendiente más viejo | Job más viejo con > 5 minutos. |
| Heartbeat del scheduler (cada comando actualiza un timestamp) | Sin heartbeat en 5 minutos. |
| Tasa de fallos de entrega de webhooks (global y por tenant) | > 20% en 1 hora a nivel global. |
| Fallos de firma en webhooks de Stripe | > 5 en 10 minutos. |
| `provider_events` en `failed` o `unroutable` | Cualquier ocurrencia. |
| Antigüedad del último FIX de Banxico | > 2 días hábiles (aviso) / > 4 días naturales (crítico, la conversión se bloquea). |
| Tasa de rechazos por tenant | > 30% con al menos 10 intentos en 1 hora (posible card testing). |
| Discrepancias de reconciliación | Cualquier discrepancia. |
| Conexiones `api_key` en `invalid_credentials` | Cualquier ocurrencia (el tenant ya fue notificado; el superadmin lo ve en su dashboard). |
| Validación previa: tasa de fallos y latencia p95 por tenant | Fallos > 20% en 1 hora o p95 > 3 s (aviso al superadmin; el tenant recibe su propia notificación). |
| Tasa de 5xx en la API y el checkout | > 1% en 5 minutos. |
| Latencia p95 de la API | > 1 segundo. |
| Espacio en disco, CPU, RAM, conexiones de MariaDB | Umbrales estándar. |
| Backups: último backup exitoso y última prueba de restauración | Backup con más de 26 horas; prueba de restauración con más de 35 días. |
| Certificados TLS | Vencimiento en menos de 14 días. |

- Canal de alertas: correo a superadmins + (recomendado) Slack, Telegram o similar.
- Monitoreo externo de uptime para `api.`, `pay.`, `app.` y un endpoint `/health`.

### 24.4 Endpoints de salud

- `/health/live`: el proceso responde.
- `/health/ready`: conexión a BD, antigüedad de la cola bajo el umbral, heartbeat del scheduler reciente. Protegido o sin información sensible.

### 24.5 Runbooks (documentar en `docs/runbooks/`)

- Cola atascada.
- Banxico no disponible o FIX desactualizado.
- Webhooks de Stripe fallando (secret rotado, versión de la API).
- Endpoint de webhook de un tenant caído.
- Sospecha de card testing en un tenant.
- Tenant reporta un pago que no ve en el panel (usar la reconciliación).
- Restauración de backup (paso a paso, probado).
- Rotación de `APP_KEY`, de `GATEWAY_CREDENTIALS_KEY`, de las llaves de Stripe o del token de Banxico.
- Sospecha de compromiso de credenciales de comercios (`api_key`): pasos para notificar a los tenants afectados, pedirles que revoquen sus restricted keys en Stripe y rotar la llave de cifrado.

---

## 25. Infraestructura y despliegue (servidor propio)

### 25.1 Topología recomendada para el MVP

- **Servidor de aplicación:** Nginx + PHP-FPM, workers de cola (Supervisor: al menos 2 procesos `queue:work`, con colas `critical` para entregas y procesamiento de eventos de Stripe, `default` y `low` para rollups y exportaciones), cron para `schedule:run` cada minuto.
- **Servidor de base de datos separado** (recomendado): MariaDB LTS, accesible solo por red privada o firewall desde el servidor de aplicación, con TLS en la conexión si cruza una red no confiable.
- **Réplica** (recomendado desde el inicio o en cuanto sea posible): réplica asíncrona para backups y failover manual.
- Firewall: solo 443 (y 80 para redirección) públicos; SSH por llave, sin root, con puerto restringido o VPN; fail2ban; actualizaciones de seguridad automáticas.
- Sincronización de hora (NTP/chrony), crítica para la validación de timestamps de webhooks.

### 25.2 Backups y recuperación

- `mariabackup` completo diario + **binlogs** para recuperación a un punto en el tiempo (PITR).
- Cifrado de los backups y copia **fuera del servidor** (almacenamiento de objetos en otra ubicación).
- Retención: diarios 14 días, semanales 8 semanas, mensuales 12 meses (ajustar a los requisitos legales).
- **Prueba de restauración mensual documentada** en un servidor aislado. Un backup no probado no cuenta.
- Objetivos iniciales: RPO ≤ 15 minutos (con binlogs) y RTO ≤ 4 horas.
- Respaldar también `APP_KEY` y `.env` de forma segura y separada.

### 25.3 Entornos

| Entorno | Stripe | Banxico | Datos |
|---|---|---|---|
| Local | Modo test + Stripe CLI (`stripe listen --forward-connect-to ...`) | Token de desarrollo o fixture | Seeders |
| CI | `FakePaymentGateway`; pruebas de contrato opcionales contra el modo test | Fixture | Factories |
| Staging | Modo test (cuenta de plataforma test) | Real | Datos de prueba; **nunca** datos reales |
| Producción | Live + test (los tenants pueden usar el modo test) | Real | Reales |

### 25.4 Despliegue

- Despliegue sin downtime con releases por symlink (Deployer, Envoy o scripts propios): `composer install --no-dev --optimize-autoloader` → build de assets → `php artisan migrate --force` (con el usuario de migraciones) → `config:cache`, `route:cache`, `view:cache`, `event:cache` → cambio de symlink → `queue:restart` → recarga de PHP-FPM.
- **Migraciones con el patrón expand/contract:** nunca eliminar ni renombrar columnas en el mismo despliegue que cambia el código que las usa. Las migraciones deben ser compatibles con la versión anterior del código durante el despliegue.
- Cuidado con los `ALTER TABLE` en tablas grandes (a futuro): revisar cuáles bloquean y usar operaciones online de MariaDB cuando sea posible.
- **CI/CD** (GitHub Actions o similar): Pint → Larastan → Pest (incluyendo la suite de aislamiento) con un servicio MariaDB **de la misma versión** que producción → `composer audit` → escaneo de secretos → despliegue a staging automático y a producción manual (con aprobación).

### 25.5 Usuarios de base de datos

- `paylink_app`: `SELECT`, `INSERT`, `UPDATE`, `DELETE` sobre el esquema de la aplicación. Sin DDL. Sin `DELETE` sobre `audit_logs` (se puede reforzar con un trigger que rechace `UPDATE`/`DELETE`).
- `paylink_migrator`: DDL; solo se usa durante el despliegue.
- `paylink_backup`: privilegios mínimos para `mariabackup`.
- `paylink_readonly` (opcional): para análisis y soporte.

---

## 26. Estrategia de pruebas

### 26.1 Pirámide

| Nivel | Qué cubre | Herramienta |
|---|---|---|
| Unitarias | Money (parseo, redondeo), FX (fórmulas, política de conversión), cálculo de comisiones de los reportes, máquinas de estado, firma de webhooks, validación SSRF, generación y verificación de API keys | Pest |
| Feature (HTTP) | Cada endpoint de la API: éxito, validaciones, autenticación, scopes, idempotencia, errores; endpoints del checkout; webhooks entrantes | Pest + `FakePaymentGateway` |
| Aislamiento de tenants | Todas las rutas y recursos (sección 6.6) | Pest (dataset de rutas) |
| Concurrencia | Doble apertura y doble pago del mismo link; dos reembolsos simultáneos; misma idempotency key en paralelo; expiración vs pago | Pest + procesos paralelos o pruebas con bloqueos simulados, contra MariaDB real |
| Contrato con Stripe | `StripeGateway` contra el modo test: crear cuenta, PaymentIntent, confirmar con tarjetas de prueba (incluidas 3DS, rechazos y tarjetas mexicanas de prueba), reembolsos | Pest con grupo `@stripe`, ejecución manual o nocturna |
| Navegador | Flujo completo del checkout (pago exitoso, rechazo, 3DS, confirmación FX, link expirado o pagado) | Pest Browser o Laravel Dusk |
| Webhooks salientes | Firma verificable con una librería de Standard Webhooks; reintentos; SSRF | Pest + servidor HTTP de prueba |

### 26.2 Casos críticos obligatorios (lista mínima)

1. Link de un solo uso abierto en dos sesiones: **solo un** PaymentIntent activo y **solo un** cobro posible.
2. Pago exitoso que llega después de la expiración → link `paid`, anomalía registrada, webhook con `late_payment: true`.
3. Webhook de Stripe duplicado → procesado una sola vez.
4. Webhooks de Stripe fuera de orden (`succeeded` antes de `processing`) → estado final correcto.
5. Tarjeta MX + cuenta MX + link USD + conversión activa → no hay cargo antes de la confirmación; tras confirmar, se cobra exactamente `converted_amount`.
6. La misma combinación con la conversión desactivada → no se crea el cargo; mensaje claro; evento registrado.
7. Cotización vencida con un cambio de monto → vuelve a pedir confirmación.
8. FIX con más de 4 días → la conversión queda bloqueada.
9. Reembolso parcial + reembolso por el resto → `refund_status = full`; un tercer reembolso → `refund_exceeds_available`.
10. Dos reembolsos simultáneos que juntos exceden el saldo → uno falla.
11. API key test contra un recurso live → `404`.
12. Tenant suspendido: crear un link → `403 tenant_suspended`; pagar un link existente → éxito.
13. URL de webhook que resuelve a `127.0.0.1` o `169.254.169.254` → bloqueada al registrar y al entregar.
14. Monto como número JSON → `400 amount_must_be_string`.
15. Idempotency key reutilizada con otro cuerpo → `422`.
16. Rate limit del checkout y activación de Turnstile tras un rechazo.
17. Job sin contexto de tenant que consulta un modelo de tenant → excepción.
18. Conexión `api_key` con una `sk_` → rechazada; `pk_` y `rk_` de distinto modo o de distinta cuenta → rechazadas; `rk_` sin permisos necesarios → rechazada.
19. La restricted key nunca aparece en logs, excepciones, payloads de jobs ni respuestas del panel.
20. Checkout con `api_key`: Stripe.js se inicializa con la `pk_` del comercio y sin `stripeAccount`; el cobro se crea en la cuenta del comercio y el webhook llega al endpoint direct.
21. Llave revocada por el comercio → el siguiente cobro o el health check marcan `invalid_credentials` y bloquean la creación de links.
22. OAuth: `state` inválido, expirado o reutilizado → rechazado; código usado una sola vez aunque el navegador envíe el callback dos veces.
23. Validación previa: `approve` → se cobra; `reject` → no se cobra y se muestra el mensaje; `reject` con `cancel_link` → link cancelado; timeout con `fail_closed` → no se cobra; timeout con `fail_open` → se cobra y queda marcado.
24. La llamada de validación no mantiene bloqueos de BD (una prueba con un servidor lento verifica que otra operación sobre el link no queda bloqueada) y el estado se re-verifica después de la llamada.

### 26.3 Datos de prueba

- Factories para todas las entidades, con estados (`->paid()`, `->expired()`, `->withFxQuote()`).
- Fixtures de payloads de webhooks de Stripe (versionados junto con la versión de la API fijada).
- Fixture de la respuesta de la API SIE de Banxico.

---

## 27. Plan de implementación por fases

> Cada fase termina con: criterios de aceptación cumplidos, pruebas en verde en CI, ADRs y OpenAPI actualizados y una demostración funcional. **No avanzar de fase con pruebas en rojo ni con deuda de seguridad conocida sin registrarla.**

### Fase 0 — Fundaciones

- Repositorio, Laravel, PHP con `strict_types`, Pint, Larastan, Pest y CI con MariaDB de la misma versión que producción.
- Docker local con MariaDB LTS, collation y `sql_mode` correctos.
- Estructura de módulos (4.3), `Shared` (Money con `brick/money`, IDs con prefijo, formato de errores de la API, `Request-Id`).
- Logging estructurado con redacción; integración con Sentry o GlitchTip.
- `docs/adr/` con los ADRs de la sección 3; esqueleto de `docs/api/openapi.yaml`.
- **Aceptación:** CI verde; una prueba verifica collation, charset y `sql_mode` de la conexión; pruebas de Money pasan.

### Fase 1 — Tenancy, identidad y acceso

- `tenants`, `TenantContext`, `BelongsToTenant` fail-closed, `BelongsToMode`, regla de Larastan, helper de pruebas de aislamiento.
- Usuarios del tenant, login, 2FA, invitaciones, re-autenticación; spatie/permission con teams; seeders de permisos y roles; Policies.
- Superadmin: tabla, guard, subdominio, 2FA; creación de tenants; estados del tenant; impersonation auditada.
- Audit log.
- Paneles Filament base (`app` y `admin`) con el selector test/live en el panel del tenant.
- **Aceptación:** pruebas de aislamiento sobre usuarios y roles; un job sin contexto falla; los roles y permisos funcionan en las Policies; el audit log registra las acciones sensibles.

### Fase 2 — Conexión con Stripe, método `platform_onboarding` (2A)

- Puerto `PaymentGateway`, `FakePaymentGateway`, `StripeGateway`, `StripeClientFactory` con los tres casos definidos (aunque solo se active `platform_onboarding`).
- `gateway_connections` con **todas** las columnas de 7.4 (incluidas las de `oauth` y `api_key`), para no migrar después.
- `GatewayCredentialsEncrypter` y `GATEWAY_CREDENTIALS_KEY` (se usan en la fase 4B, pero la infraestructura se deja lista y probada).
- Flujo de onboarding con Account Links, retorno y refresco, UI de estado y requisitos.
- Endpoint de webhooks de Stripe Connect (verificación, `provider_events`, job, handlers de `account.updated` y `account.application.deauthorized`).
- **Aceptación:** en modo test, un tenant crea su cuenta conectada, completa el onboarding y queda `active`; un cambio en Stripe se refleja vía webhook; los eventos duplicados no se reprocesan; la fábrica de clientes tiene pruebas para los tres métodos.

### Fase 3 — API de links

- `api_keys` (panel para crear y revocar; middleware de autenticación; scopes; prefijos por modo).
- Idempotencia (`idempotency_records`).
- `POST/GET/LIST /v1/payment_links`, `POST /v1/payment_links/{id}/cancel`, validaciones completas (8.2, 10.5), máquina de estados del link, job de expiración.
- Rate limiting de la API.
- Panel: listado, detalle y creación manual de links.
- OpenAPI actualizado.
- **Aceptación:** todos los casos de validación y errores con pruebas; idempotencia probada (incluida la concurrencia); aislamiento probado; la expiración funciona.

### Fase 4 — Checkout y pagos con tarjeta (sin FX)

- **Spike técnico (máximo 1–2 días):** validar con Stripe en modo test el flujo deferred intent + ConfirmationToken con direct charges (`stripeAccount`), la inspección de `payment_method_preview.card.country`, la actualización de moneda y monto del PaymentIntent antes de confirmar y el comportamiento de `elements.update()`. Documentar el resultado en un ADR. **Si el comportamiento difiere de lo previsto en 11.4 y 13, detenerse y reportar antes de continuar.**
- Página de pago (estados, branding básico, campos del pagador, Payment Element), endpoints internos, intentos (un PaymentIntent activo por link con restricción de BD), 3DS, página de completado y polling.
- Handlers de `payment_intent.*`; reconciliación de intentos.
- Protección anti card testing (rate limits, Turnstile, bloqueos), cabeceras de seguridad y CSP.
- Registro de aperturas.
- **Aceptación:** pago exitoso, rechazo, 3DS, link pagado, expirado o cancelado; casos críticos 1–4, 16 y 17 de la sección 26.2; prueba de navegador del flujo completo.

### Fase 4B — Métodos de conexión adicionales: `oauth` (2B) y `api_key` (2C)

> Se hace después de la fase 4 para poder probar cada método de punta a punta con pagos reales en modo test.

- **Spike (máximo 1 día):** (a) confirmar la disponibilidad de OAuth para la plataforma; (b) definir el mecanismo para validar que la `pk_` y la `rk_` pertenecen a la misma cuenta; (c) confirmar la lista exacta de permisos de restricted key necesarios y los nombres vigentes en Stripe; (d) confirmar que una `rk_` puede crear webhook endpoints. Documentar en un ADR. **Si OAuth no está disponible, se implementa solo 2C y se deja `oauth` deshabilitado por configuración.**
- **2B — OAuth:** flujo completo de 12.3.2 (`state`, callback, intercambio único del código, deauthorize, manejo de cuentas ya vinculadas).
- **2C — API key:** formulario, aviso de riesgo, validaciones de 12.3.3 (prefijos, modo, cuenta, correspondencia de `pk_`/`rk_`, permisos, unicidad), cifrado, creación del webhook endpoint remoto, endpoint `/webhooks/stripe/direct/{connection_id}`, health check diario, estado `invalid_credentials`, actualización de llaves y desconexión con limpieza.
- Checkout y reembolsos funcionando con los tres métodos (la vista del checkout solo usa `CheckoutClientConfig`).
- **Aceptación:** casos críticos 18–22; pago exitoso, rechazo y reembolso probados en modo test con cada método; ninguna restricted key en logs (verificado con una prueba que inspecciona los logs generados).

### Fase 5 — Webhooks salientes y validación previa al cobro

- Endpoints de webhooks (panel), secretos cifrados, rotación, outbox, `DeliverWebhookJob`, calendario de reintentos, deshabilitación por fallos, log de entregas, reenvío manual, evento `ping`, protección SSRF completa, barredor.
- `GET /v1/events`.
- **Validación previa al cobro (15.8):** `validation_endpoints`, configuración en el panel, botón de prueba, integración en el flujo del checkout (fuera de bloqueos), políticas `fail_closed`/`fail_open`, `cancel_link`, `validation_calls`, visibilidad en el panel, campo `pre_payment_validation` en la API y bloque `pre_validation` en los pagos y webhooks.
- Documentación para integradores (verificación de firma con ejemplos; contrato de la validación previa con ejemplos de servidor en PHP, Node y Python; recomendación de reserva de stock).
- **Aceptación:** firmas verificables con una librería de Standard Webhooks; reintentos según el calendario; casos críticos 13, 23 y 24; entregas at-least-once probadas con fallos simulados; la validación previa agrega como máximo 5 segundos al checkout en el peor caso.

### Fase 6 — Conversión de moneda

- `exchange_rates`, `FetchBanxicoFixJob`, validaciones de cordura y antigüedad, configuración FX del tenant, `ConversionPolicy`, `fx_quotes`, leyenda FX, pantalla de confirmación, integración en el flujo del checkout, campos FX en la API y los webhooks.
- **Aceptación:** casos críticos 5–8; tabla de verdad completa de la política de conversión; pruebas de navegador del flujo de confirmación.

### Fase 7 — Reembolsos y disputas

- Reembolsos (API y panel), sincronización desde el dashboard de Stripe, disputas (registro y notificación), `refund_status` y `dispute_status`.
- **Aceptación:** casos críticos 9–10; reembolsos externos importados correctamente.

### Fase 8 — Métricas, branding completo y campos del pagador

- Rollups diarios, dashboard del tenant, exportación CSV.
- Branding completo (logo re-codificado, contraste, vista previa).
- Catálogo de campos del pagador, configuración por tenant y por link, cifrado, retención y purga.
- **Aceptación:** métricas correctas en la zona horaria del tenant (pruebas con pagos cerca de la medianoche); sin suma entre monedas; purga de PII probada.

### Fase 9 — Plataforma: planes, reportes de uso y operación

- Planes tarifarios versionados, reportes de uso mensuales, matriz de estados del tenant aplicada en toda la API y el panel, dashboard de plataforma, alertas y notificaciones.
- **Aceptación:** caso crítico 12; reporte reproducible (regenerarlo da el mismo resultado); cambio de plan a mitad de mes calculado correctamente.

### Fase 10 — Endurecimiento y salida a producción

- Revisión de seguridad completa, pruebas de carga ligeras (para validar que no hay cuellos de botella evidentes), runbooks, backups con restauración probada, monitoreo y alertas verificados, documentación para integradores y checklist de la sección 30.
- Piloto con 1–3 tenants reales antes de abrir el acceso.

---

## 28. Fuera de alcance / fases futuras

| Elemento | Motivo del aplazamiento | Preparación ya incluida en el diseño |
|---|---|---|
| Otras pasarelas (Mercado Pago, Conekta, OpenPay) | Salir primero con Stripe | Puerto `PaymentGateway`, `provider` en las tablas, estados normalizados, `clientAction = redirect_url`. |
| Apple Pay / Google Pay | Conflicto con el flujo FX (ADR-009) | Configurable por tenant a futuro; se puede habilitar en cuentas sin conversión. |
| OXXO, SPEI, meses sin intereses | Métodos asíncronos y reglas propias | Estado `processing` y eventos `payment.processing` ya existen. |
| Links reutilizables (`max_uses`) | Simplicidad del MVP | Modelo link 1:N intentos. |
| Dominios personalizados por tenant | Emisión de TLS por tenant | Branding desacoplado del dominio. |
| Roles personalizados por el tenant | Simplicidad | Roles como datos en BD. |
| Firma de webhooks Ed25519 | Complejidad para integradores pequeños | Formato Standard Webhooks compatible. |
| Gestión de webhooks, API keys y branding vía API | Seguridad y alcance | — |
| Usuarios en múltiples tenants | Simplicidad | — |
| Emisión de CFDI | Decisión de negocio | `metadata` disponible para el integrador. |
| Gestión de evidencia de disputas desde el panel | Alcance | Tabla `disputes` existente. |
| Prellenado de datos del pagador | Alcance | Catálogo de campos listo. |
| Onboarding embebido de Stripe | Alcance | El flujo de Account Links funciona sin él. |
| Validación previa **al abrir** el link (además de antes del cobro) | Bloquea la carga de la página por el servidor del tenant; la validación antes del cobro cubre el caso principal | `validation_endpoints` reutilizable; solo requiere otro punto de llamada. |
| Firma de la respuesta de la validación previa | TLS ya autentica al servidor del tenant | Formato de headers compatible. |
| Gestor de secretos (Vault) para `GATEWAY_CREDENTIALS_KEY` | Infraestructura adicional | Encriptador dedicado y versionado de la llave. |
| Pool + silo (BD dedicada para enterprise) | No hay demanda | Aislamiento por `tenant_id` y `TenantContext` centralizado. |
| Redis / Horizon | Volumen bajo | Colas con nombres ya separados (`critical`, `default`, `low`). |
| SDKs oficiales (PHP, Node) para integradores | Alcance | OpenAPI mantenido para generarlos. |

---

## 29. Preguntas abiertas y decisiones pendientes de confirmar

| # | Tema | Estado | Recomendación | Bloquea |
|---|---|---|---|---|
| 1 | **Métodos de conexión con Stripe** (ADR-004) | `DECIDIDO`: los tres (`platform_onboarding`, `oauth`, `api_key`) | `platform_onboarding` como default en la UI; `api_key` solo con restricted keys | — |
| 1a | Disponibilidad de OAuth para nuestra plataforma (Stripe no lo recomienda para plataformas nuevas) | `PENDIENTE` | Verificar en el dashboard de Connect o con el soporte de Stripe; si no está disponible, `oauth` queda deshabilitado | Fase 4B |
| 1b | Mecanismo para validar que `pk_` y `rk_` pertenecen a la misma cuenta, y lista exacta de permisos de la restricted key | `PENDIENTE` | Spike de la fase 4B | Fase 4B |
| 1c | Texto legal del aviso de riesgo del método `api_key` y cláusula de responsabilidad en el contrato | `PENDIENTE` | Redactar con asesoría legal | Fase 4B |
| 1d | Política por defecto de la validación previa (`fail_closed` propuesto) y timeout (5 s propuesto) | `PROPUESTO` | Confirmar | Fase 5 |
| 2 | País de la cuenta de plataforma en Stripe y países permitidos para cuentas conectadas (cross-border) | `PENDIENTE` | Verificar con la documentación y el soporte de Stripe antes de la fase 2 | Fase 2 |
| 3 | Controller properties exactas y costos de Connect para la plataforma | `PENDIENTE` | Configuración equivalente a Standard (el comercio paga comisiones y pérdidas) | Fase 2 |
| 4 | Destinatarios de correos: se interpretó "admin de la plataforma" como superadmins (alertas de plataforma) + usuarios del tenant (operativas) | `SUPUESTO` | Confirmar | Fase 9 |
| 5 | Valores por defecto de expiración (propuesta: 7 días por defecto, 90 días máximo, 15 minutos mínimo) | `PROPUESTO` | Confirmar | Fase 3 |
| 6 | Montos mínimos y máximos por moneda | `PENDIENTE` | Mínimos de Stripe vigentes + máximos de riesgo de la plataforma | Fase 3 |
| 7 | Retención de PII del pagador (propuesta: 24 meses) y aviso de privacidad del tenant obligatorio | `PROPUESTO` | Validar con asesoría legal (contrato de encargo de tratamiento de datos) | Fase 8 |
| 8 | IVA y obligaciones fiscales del operador al cobrar a sus tenants | `PENDIENTE` (fuera del sistema) | Validar con el contador: cobrar IVA en México normalmente implica emitir CFDI | Negocio |
| 9 | Términos y condiciones: comisiones no reembolsables (ADR-012), suspensión (ADR-013), responsabilidades de disputas | `PENDIENTE` | Redactar con asesoría legal | Salida a producción |
| 10 | Nombre comercial, dominios y prefijo de llaves definitivos | `PENDIENTE` | — | Fase 0 (prefijos) |
| 11 | Tope de markup FX (propuesta: 10%) | `PROPUESTO` | Confirmar | Fase 6 |
| 12 | Límite de endpoints de webhook por tenant (propuesta: 5 por modo) y límites de rate de la API | `PROPUESTO` | Confirmar | Fases 3 y 5 |
| 13 | Proveedor de correo transaccional y de monitoreo de errores | `PENDIENTE` | Postmark/SES; GlitchTip autoalojado o Sentry | Fase 0 |

---

## 30. Checklist de salida a producción

**Seguridad**
- [ ] Suite de aislamiento entre tenants cubre el 100% de las rutas y está en verde.
- [ ] 2FA obligatorio verificado para los roles sensibles y los superadmins.
- [ ] Re-autenticación activa en todas las acciones sensibles.
- [ ] CSP y cabeceras de seguridad verificadas en el checkout y los paneles.
- [ ] Protección SSRF probada con destinos internos, IPv6 y DNS rebinding.
- [ ] Anti card testing activo (rate limits, Turnstile, bloqueos, alertas).
- [ ] Redacción de logs verificada (sin secretos ni PII).
- [ ] `composer audit` / `npm audit` sin vulnerabilidades altas; escaneo de secretos limpio.
- [ ] Superadmin accesible solo desde el subdominio propio con allowlist o VPN.
- [ ] Método `api_key`: rechazo de `sk_` verificado; restricted keys ausentes de logs, Sentry y jobs; health check activo.
- [ ] Método `oauth`: `state` de un solo uso y protección de doble intercambio verificados (si está habilitado).

**Pagos y correctitud**
- [ ] Los 24 casos críticos de la sección 26.2 pasan.
- [ ] Pago y reembolso probados en live (montos mínimos) con cada método de conexión habilitado.
- [ ] Validación previa probada con un servidor de ejemplo (aprobación, rechazo, timeout con ambas políticas).
- [ ] Versión de la API de Stripe fijada en el cliente y en los endpoints de webhook.
- [ ] Webhooks de Stripe live configurados con los eventos de la sección 14.3 y los secrets correctos.
- [ ] Reconciliación ejecutada en staging sin discrepancias.
- [ ] Banxico: job funcionando en producción, alertas de antigüedad probadas.
- [ ] Flujo FX probado de punta a punta con tarjetas de prueba mexicanas y extranjeras.

**Operación**
- [ ] Backups automáticos + binlogs + copia fuera del servidor + **restauración probada**.
- [ ] `APP_KEY` y `GATEWAY_CREDENTIALS_KEY` respaldadas por separado entre sí y de los backups de BD.
- [ ] Monitoreo de uptime, alertas y heartbeat del scheduler funcionando.
- [ ] Runbooks escritos.
- [ ] Usuarios de BD con privilegios mínimos.
- [ ] Hora del servidor sincronizada.

**Documentación y legal**
- [ ] Documentación para integradores: autenticación, idempotencia, montos como string, errores, webhooks (verificación con ejemplos), patrón fetch-back, FX (campos originales vs cobrados).
- [ ] OpenAPI publicado.
- [ ] Términos y condiciones y aviso de privacidad de la plataforma publicados; contrato de encargo de datos con los tenants.
- [ ] Decisiones pendientes de la sección 29 resueltas o aceptadas explícitamente.

---

*Fin del plan maestro. Cualquier cambio a una decisión `DECIDIDO` debe registrarse como un nuevo ADR que reemplace al anterior, indicando el motivo.*