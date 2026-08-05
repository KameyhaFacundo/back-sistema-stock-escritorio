# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Laravel 10 API backend for **Stockana**, a white-label, multi-tenant inventory/POS system. Sibling repo `front-sistema-stock` (React + Vite SPA) is the only consumer of this API — permission codes, plan limits, and route contracts must stay in sync with it. UI-facing strings and code comments are in Spanish.

**`README.md` is stale/generic** — it reads like a scaffolding tutorial (a walk-through of adding a fictional "Clientes" CRUD module) and its description of the permissions system does **not** match the current code (see below). Don't take it as source of truth; this file describes what the code actually does.

## Commands

- `composer install` — install PHP deps
- `cp .env.example .env && php artisan key:generate && php artisan jwt:secret` — first-time setup
- `php artisan migrate` / `php artisan migrate:fresh --seed` — run migrations (optionally wipe + reseed)
- `php artisan db:seed --class=PermisoSeeder` — reseed just the permissions catalog after adding new codes
- `php artisan serve` — dev server
- `php artisan test` or `vendor/bin/phpunit` — run the suite (`tests/Unit` + `tests/Feature`)
- `vendor/bin/phpunit --filter testName` — single test
- `vendor/bin/pint` — Laravel Pint code style fixer
- `php artisan route:list` — inspect registered routes (useful given the dual-mount below)

## Architecture

### Multi-tenancy
`app/Models/Concerns/HasTenant.php` is a trait applied to every tenant-scoped model (`Producto`, `Venta`, `Compra`, `Cliente`, `Proveedor`, `Categoria`, `Sucursal`, `Turno`, `Lote`, `Factura`, `MovimientoStock`, `ProductoStock`, `ComboComponente`, `MovimientoPuntos`, `VentaPointIntento`, `HistorialPermiso`, ...). It adds a global scope filtering every query by `empresa_id` of the authenticated user, and auto-fills `empresa_id` on create. **New tenant-scoped models must `use HasTenant`** or they'll silently leak data across companies.

`CheckEmpresaActiva` middleware (alias `empresa.activa`, wraps most of `routes/api.php`) blocks requests with a `403` when the company is `suspendida` or its `trial_ends_at` has passed — except for `is_super_admin` users, and except for `POST suscripcion/crear` (deliberately left outside the group so a blocked company can still pay to unblock itself).

### Permissions — NOT what the README describes
Despite the `roles` ↔ `rol_permisos` ↔ `permisos` tables existing, **roles do not grant permissions at request time**. `User::chequearPermisos()` (`app/Models/User.php`) only checks the user's own `permisos_usuarios` pivot rows. A role is just a label; assigning/changing a user's `id_rol` copies that role's permission set into `permisos_usuarios` **once**, as a preset (see `UsersController::store`/`update` — explicit `permisos` in the request always wins over the role preset). If you're debugging "why doesn't changing the role affect access", this is why — look at `permisos_usuarios`, not the role.

- Route-level enforcement: `VerificarPermisos` middleware (alias `permisos.verify:<codigo>`), applied per-route in `routes/api.php`.
- `is_super_admin` is a **separate** flag from the permission system — it only unlocks the `/super-admin/*` routes and bypasses `CheckEmpresaActiva`. It must never be treated as an implicit permissions bypass for normal tenant routes.
- Adding a new permission: add to `database/seeders/PermisoSeeder.php`, reseed, add `->middleware('permisos.verify:codigo-nuevo')` to the route, and add the matching entry to `PERMISOS_MAP` in `front-sistema-stock/src/hooks/useHasPermiso.jsx` (and to `DEMO_PERMISOS_CODIGOS` in that repo's `demoMode.js` if it should be visible in demo mode).

### Routes are dual-mounted
`routes/api.php` builds the whole route table inside a closure (`$apiRoutes`) and calls it twice: once under `Route::prefix('v1')` and once unprefixed for legacy clients. **Any route added inside that closure is automatically available at both `/api/v1/...` and `/api/...`** — don't manually duplicate a route in both places.

### Plans & billing
`config/planes.php` defines per-plan resource limits (`productos`, `usuarios`, `sucursales`; `null` = unlimited) and feature flags (`catalogo`, `ia`, `cobros`, `facturacion`) for plans `free` (7-day trial, capped at the `esencial` tier), `esencial`, `pro`, `ia`. `app/Http/Controllers/Concerns/ChecksPlanLimits.php` is the trait controllers use to enforce these (`limitePlanExcedido`, `funcionNoDisponibleEnPlan`). **This config must stay in sync with what's marketed in `front-sistema-stock/src/pages/Planes` and `Landing`** — the comment at the top of the file says so explicitly.

Mercado Pago handles both subscription billing (`SubscripcionController`, webhook is public/unauthenticated) and in-person payments (`PagoPointController` for card readers/Point, plus QR) via a per-company OAuth connection (`MercadoPagoConexionController`, `MercadoPagoConexion` model, `MercadoPagoTokenService`).

### Integrations living in `app/Services/`
Business logic that doesn't belong in a fat controller or an Eloquent model:
- `StockService` — stock movements/adjustments
- `VentaCreacionService` — sale creation (likely the place touching stock, caja, deudas, puntos together — check here first for cross-cutting sale bugs)
- `TurnoService` — cash register shift (caja) lifecycle
- `ArcaService` — Argentina electronic invoicing (AFIP/ARCA), backs `FacturaController`
- `GeminiService` — Google Gemini AI, backs `IaController` (product suggestions/images) and `AsistenteController` (in-app assistant that can answer questions about the company's own data); gated behind the `ia` plan feature
- `MercadoPagoTokenService` — OAuth token refresh/storage for the MP connection

### Auth
JWT via `tymon/jwt-auth` (`jwt.verify` middleware), with optional TOTP 2FA (`pragmarx/google2fa`, `TwoFactorController`, `/api/login/2fa`). `SuperAdminController::impersonar` issues a token for a tenant user on behalf of a super-admin (paired with the front's `impersonarEmpresa`/`volverDeImpersonar`).

### Async work
`app/Jobs/`: `ScanInvoiceJob` (OCR/parsing for `EscanearController`'s "scan a supplier invoice" feature), `SendEmailJob`, `SendWelcomeEmailJob`.
