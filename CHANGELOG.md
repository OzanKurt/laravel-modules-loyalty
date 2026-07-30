# Changelog

All notable changes to `ozankurt/laravel-modules-loyalty` will be documented in this file.

## v2.0.0

### Breaking changes
- Requires PHP 8.4+ (drops PHP 8.3).
- Requires Laravel 13 (drops Laravel 12).
- Requires `ozankurt/laravel-modules-core` ^2.0.
- Test suite runs on Pest 5 / Testbench 11.

No loyalty behaviour changed: stamps, tiers, vouchers, redemption, expiry, wallet
passes and the `LoyaltyStatsService` cache boundary (report cached by TTL, live
card balances never cached) are byte-for-byte identical and pass unmodified.

### Added (feature round)
- **Multi-tier rewards.** New `loyalty_program_tiers` table + `ProgramTier` model (`Program hasMany tiers`). When a program defines tiers, each crossed absolute `threshold` earns that tier's reward and fires a `TierReached` event (plus `CardCompleted`). Programs with no tier rows are unchanged (single repeating `stamps_required` threshold, rollover crediting preserved).
- **Analytics.** `LoyaltyStatsService` aggregates cards, stamps, and reward earn/redeem funnels overall and per program (optional program + date-range filters, no N+1). Exposed via the `loyalty:stats` command and a staff-gated `GET /loyalty/stats` JSON endpoint (registered in `api` + `ui` modes, not `headless`).
- **Stamp / reward expiry.** Config defaults (`loyalty.expiry.*`) and per-program `stamp_expiry_days` / `reward_expiry_days` columns (`null` = never expires). The schedulable, idempotent `loyalty:expire` command voids stale stamps and unredeemed earned rewards (new `rewards_expired` counter on cards) in locked transactions, firing `StampsExpired` / `RewardExpired`.

### Added (hardening round 2)
- **Terminal rate limiting** — the staff terminal stamp/redeem endpoints get their own `loyalty.routes.terminal_rate_limit` throttle.
- **Idempotency keys** — stamp/redeem accept an `Idempotency-Key` header (or `idempotency_key` field); a key seen within `loyalty.idempotency.ttl` is a replay and is not re-applied. The JS terminal disables buttons in-flight and sends a key per gesture, so double-taps beyond the cooldown can't add extra stamps.
- **i18n** — shipped terminal + wallet strings moved to `lang/{en,tr}/messages.php` under a clean `loyalty::` namespace (publishable).
- **Accessibility** — card page gets `aria-live` progress, `role="img"` + labels on the stamp grid and QR, a labelled terminal input, and a `prefers-reduced-motion` guard on the stamp animation.
- **Wallet live push (implemented)** — replaces the stub: Apple Wallet web service (device register/unregister/serials/pass/log with ApplePass-token auth) + cert-based APNs sender, and Google loyalty-object PATCH via an OAuth2 service-account token. A queued `PushWalletUpdate` job runs on stamp/complete/redeem events when `LOYALTY_WALLET_PUSH=true`.

### Security
- **Split card identifiers.** The public URL/QR now uses a long 128-bit `token` (hard to harvest or enumerate), while a short human-typeable `code` is used for counter/manual lookup at the staff-gated terminal. Sharing a card link stays frictionless; the shareable value is no longer a short, guessable string.
- **One-time claim.** In `anonymous_claimable` mode a card can no longer be re-claimed once an identity is attached, so knowing a token can't be used to hijack an already-claimed card (`CardAlreadyClaimedException`, HTTP 409).

### Added
- **HTTP modes.** `loyalty.http.mode` (`LOYALTY_HTTP_MODE`) selects how much of the HTTP surface registers: `headless` (nothing), `api` (JSON + resource endpoints only), or `ui` (default; API + shipped HTML card page & terminal + views/assets). The domain services stay available in every mode; the card page also honours `Accept: application/json`.
- **M1 — Foundation:** package scaffold, `loyalty_*` schema (programs, cards, vouchers, stamps, redemptions, wallet passes/registrations), models + factories, `LoyaltyCustomer` contract + `IsLoyaltyCustomer` trait, and the domain services: `CardService` (3 identity modes + claim), `VoucherService` (single-use issue/redeem), `StampService` (cooldown / daily-cap / idempotency / threshold-crossing completion), `RedemptionService` (reset or rollover). Eight domain events, no default listeners.
- **M2 — HTTP surface:** public card page + `/state` JSON, card creation, claim, voucher redemption, and a staff terminal (`stamp` / `redeem`) behind the deny-by-default `loyalty:staff` gate, with rate limiting on public writes.
- **M3 — Frontend:** framework-free JS behavior layer keyed to a stable `data-*` contract, Vite build (committed `dist`), contract stylesheet + `coffee`/`hotdog`/`restaurant`/`minimal` themes (light + dark) with a CSS-mask icon system, server-side inline-SVG QR codes (`bacon/bacon-qr-code`, no JS required), and a polling live-update channel. Vitest unit tests.
- **M4 — Wallet & commands:** `WalletProvider` seam with Apple (`.pkpass`) and Google (signed save-link JWT) providers, add-to-wallet endpoints + conditional buttons, opt-in live-push seam wired to card events, and the `loyalty:install` / `loyalty:demo` / `loyalty:prune-vouchers` / `loyalty:wallet-check` commands.
