# KurtModules Loyalty

[![tests](https://github.com/OzanKurt/laravel-modules-loyalty/actions/workflows/tests.yml/badge.svg)](https://github.com/OzanKurt/laravel-modules-loyalty/actions/workflows/tests.yml)

Digital loyalty / stamp-card system for Laravel apps — collect stamps, earn rewards, redeem them, with a public card page, QR-based granting, and Apple/Google Wallet passes.

Part of the [KurtModules](https://github.com/OzanKurt) family. Requires `ozankurt/laravel-modules-core`.

## Requirements

- PHP `^8.4`
- Laravel `^13.0`

## A deliberate departure from the family

Every other KurtModules package is headless + Filament-only with no public views. **Loyalty is the exception by design:** it is a *customer-facing* surface, so it ships public routes, Blade views, and compiled JS/CSS assets, and **no Filament panel**. It still follows every other family convention (Core-based provider, anonymous publishable migrations, Pest + Testbench, SemVer).

## Architecture

- **Headless behavior, swappable skin.** One vanilla-JS behavior layer keyed to a stable `data-*` attribute contract; the entire stylesheet is replaceable without touching behavior. Ships `coffee`, `hotdog`, `restaurant`, and `minimal` themes (light + dark), all overridable.
- **Unified voucher primitive.** Every stamp is granted by redeeming a single-use `Voucher` — whether issued by a staff terminal, printed on a receipt, or shown as a till QR.
- **Configurable identity.** Cards can be anonymous (Mars-style), user-bound, or anonymous-then-claimable — chosen per install.
- **Wallet via an adapter seam.** Apple `.pkpass` + Google Wallet behind one `WalletProvider` interface; live-updating passes are opt-in (certs/service account are the consuming app's to supply).
- **Built on the shared Core API kit.** The JSON controllers extend Core's `ApiController`, and the staff terminal throttles through Core's shared `ApiRateLimiter` (`throttle:loyalty-api`), so Loyalty rides the same HTTP foundation as every other module. The `loyalty.http.mode` contract (`headless`/`api`/`ui`) maps directly onto Core's `HttpMode` — same string values, same semantics. Customer-facing bodies (the card `/state` JSON, terminal stamp/redeem, wallet/PassKit payloads) keep their exact historic shape; the Core success/error envelope (`{ "data": … }` / `{ "message": …, "errors": … }`) is adopted on the staff analytics endpoint (`GET /loyalty/stats`).

## Status — all milestones landed ✅

- **M1** — package foundation, schema, models/factories, domain services (Card, Voucher, Stamp, Redemption).
- **M2** — HTTP surface: customer card routes, `/state` JSON, voucher redemption, staff terminal behind the `loyalty:staff` gate, rate limiting.
- **M3** — frontend: vanilla-JS behavior layer (data-attribute contract), Vite build with committed `dist`, contract stylesheet + `coffee`/`hotdog`/`restaurant`/`minimal` themes (light + dark). QR codes render server-side as inline SVG (`bacon/bacon-qr-code`), so they work without JavaScript.
- **M4** — Apple `.pkpass` + Google Wallet providers behind a `WalletProvider` seam, add-to-wallet endpoints + buttons, **live push** (Apple Wallet web service + APNs, Google object PATCH) via a queued job on card events, and artisan commands (`loyalty:install`, `loyalty:demo`, `loyalty:prune-vouchers`, `loyalty:wallet-check`).

## Quick start

```bash
composer require ozankurt/laravel-modules-loyalty
php artisan loyalty:install   # publish config, migrations, views, assets
php artisan migrate            # creates loyalty_* tables incl. loyalty_program_tiers + the expiry columns
php artisan loyalty:demo       # seed a program + card, prints the card URL
```

`loyalty:install` publishes every migration, including the tier table (`loyalty_program_tiers`) and the expiry columns (`stamp_expiry_days` / `reward_expiry_days` on programs, `rewards_expired` on cards) — so a single `php artisan migrate` sets up the full schema.

Define who staff are (the terminal is deny-all until you do):

```php
// AuthServiceProvider
Gate::define('loyalty:staff', fn ($user) => $user->is_staff);
```

Wallet passes are opt-in — set the Apple certificate / Google service-account env vars, then `php artisan loyalty:wallet-check`. Live pass updates need `LOYALTY_WALLET_PUSH=true` plus a queue worker.

## HTTP modes

Pick how much of the HTTP surface the package registers with `LOYALTY_HTTP_MODE` (config `loyalty.http.mode`). The domain services + events are always available regardless — the controllers are a thin adapter over them, never the source of truth.

| Mode | Registers | Use when |
|---|---|---|
| `ui` (default) | JSON/resource endpoints **+ shipped HTML card page & terminal + views/assets** | You want the full Mars-style experience out of the box. |
| `api` | JSON + resource endpoints only (state, create, claim, voucher redeem, terminal stamp/redeem, wallet passes). No Blade, no assets. | You're building your own frontend but want the HTTP layer. |
| `headless` | Nothing. | You want to wire your own routes/controllers and call `CardService` / `StampService` / `VoucherService` / `RedemptionService` directly. |

`ui` ⊇ `api`. Even in `ui`, the card page route honours `Accept: application/json` and returns state instead of HTML.

`loyalty.http.mode` resolves through Core's `Kurt\Modules\Core\Http\HttpMode` enum (identical `headless`/`api`/`ui` string values). The staff terminal's throttle budget lives in `loyalty.http.rate_limit` (`maxAttempts,decayMinutes`, default `60,1`), registered as the shared `throttle:loyalty-api` limiter; the public write endpoints (create / claim / voucher redeem) keep their own tighter `loyalty.routes.rate_limit` budget (default `30,1`).

## Frontend

The behavior layer is framework-free and keyed to a stable `data-*` attribute contract, so the entire stylesheet is swappable without touching JS. Rebuild/extend it with your own pipeline:

```bash
npm install
npm run build   # -> resources/dist (loyalty.js, loyalty.css, themes/*)
npm run test    # Vitest
```

**Data-attribute contract** (rewrite the Blade freely as long as these survive):

| Attribute | Role |
|---|---|
| `data-loyalty-card` / `data-loyalty-token` / `data-loyalty-state-url` | card root + poll target |
| `data-loyalty-stamps` → `data-loyalty-stamp[data-state]` | the stamp grid (JS toggles `data-state`) |
| `data-loyalty-progress` / `data-loyalty-count` / `data-loyalty-required` | progress (live region) |
| `data-loyalty-qr` / `data-loyalty-wallet="apple\|google"` | QR + wallet buttons |
| `data-loyalty-terminal` + `data-loyalty-{scanner,card-input,stamp-btn,redeem-btn,terminal-result}` | staff terminal |

## Wallet setup

Both providers are opt-in and only render buttons once configured. Env vars:

- **Apple:** `LOYALTY_APPLE_WALLET=true`, `LOYALTY_APPLE_PASS_TYPE_ID`, `LOYALTY_APPLE_TEAM_ID`, `LOYALTY_APPLE_CERTIFICATE` (+ `_PASSWORD`), `LOYALTY_APPLE_WWDR_CERTIFICATE`.
- **Google:** `LOYALTY_GOOGLE_WALLET=true`, `LOYALTY_GOOGLE_ISSUER_ID`, `LOYALTY_GOOGLE_CLASS_ID`, `LOYALTY_GOOGLE_SERVICE_ACCOUNT` (path to the JSON key).
- **Live push:** `LOYALTY_WALLET_PUSH=true` + a queue worker (Apple via APNs using the pass cert, Google via object PATCH). Run `php artisan loyalty:wallet-check` to verify.

## Multi-tier rewards

A program can define a ladder of `ProgramTier` rows (`loyalty_program_tiers`) instead of a single repeating goal. Each tier has an absolute `threshold` (cumulative stamp count), a `reward` label, an optional `reward_payload` JSON (voucher config / metadata), and a `position` for ordering:

```php
$program->tiers()->createMany([
    ['threshold' => 3,  'reward' => ['en' => 'Free cookie'],   'position' => 1],
    ['threshold' => 6,  'reward' => ['en' => 'Free coffee'],   'position' => 2],
    ['threshold' => 10, 'reward' => ['en' => 'Free tumbler'],  'position' => 3],
]);
```

When a stamp crossing meets the next unmet tier threshold, that tier's reward is credited to `rewards_earned` and a `TierReached` event (carrying the `Card` and `ProgramTier`) fires, alongside the existing `CardCompleted` event. A single multi-stamp voucher that vaults past several thresholds credits each crossed tier.

**Fully backward compatible:** a program with *no* tier rows behaves exactly as before — the single `stamps_required` threshold, repeating, with the same rollover crediting for `reset_on_reward = false`.

## Analytics

`LoyaltyStatsService::overview(?int $programId, ?Carbon $since, ?Carbon $until)` returns cards issued, active cards, stamps granted, rewards earned/redeemed, and the redemption rate — overall (`totals`) and per program (`programs`) — from a handful of grouped aggregate queries (no N+1). The optional program filter and date range narrow the population.

- **Command:** `php artisan loyalty:stats [--program=id|slug] [--since=date] [--until=date]` prints the breakdown as a table.
- **Endpoint:** `GET /loyalty/stats` returns the same data as JSON for a consumer dashboard, wrapped in the Core envelope under a top-level `data` key (`{ "data": { "range": …, "totals": …, "programs": … } }`). It is behind the `loyalty:staff` gate and registered only in `api` and `ui` modes (never `headless`). Accepts `program`, `since`, `until` query params.

## Stamp / reward expiry

Stamps and unredeemed earned rewards can expire after a configurable window. Defaults live in `config/loyalty.php` under `expiry` (`LOYALTY_STAMP_EXPIRY_DAYS` / `LOYALTY_REWARD_EXPIRY_DAYS`), and each program can override them via its `stamp_expiry_days` / `reward_expiry_days` columns. `null` everywhere = **never expires** (today's behaviour).

```bash
php artisan loyalty:expire   # schedulable; safe to run repeatedly
```

The command voids (zeroes) stamps on cards whose last activity is older than `stamp_expiry_days`, and voids unredeemed earned rewards (tracked in the new `rewards_expired` counter, kept separate from redemptions so analytics stay accurate) older than `reward_expiry_days`. Each runs inside a locked transaction and fires `StampsExpired` / `RewardExpired`. It is idempotent — a second run is a no-op. Schedule it in the consuming app:

```php
// routes/console.php or the console kernel
Schedule::command('loyalty:expire')->daily();
```

See [`docs/superpowers/specs`](docs/superpowers/specs) for the full design and [`docs/superpowers/plans`](docs/superpowers/plans) for the build plan.

## Testing

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pest
```

CI runs the same checks on every push and pull request
(`.github/workflows/tests.yml`), against PHP 8.4 / Laravel 13. Static analysis
is held at **PHPStan level 8**; the suite runs on **Pest 5**.

## License

MIT © [Ozan Kurt](mailto:ozankurt2@gmail.com)
