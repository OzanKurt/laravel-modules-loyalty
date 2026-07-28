<?php

declare(strict_types=1);

use Kurt\Modules\Loyalty\Enums\IdentityMode;

return [
    /*
    |--------------------------------------------------------------------------
    | Identity mode
    |--------------------------------------------------------------------------
    | How a card is owned:
    |   IdentityMode::Anonymous          - token URL only, never claimable
    |   IdentityMode::User               - always bound to an authenticated user
    |   IdentityMode::AnonymousClaimable - starts anonymous, claimable later
    */
    'identity_mode' => IdentityMode::AnonymousClaimable->value,

    /*
    |--------------------------------------------------------------------------
    | Anti-fraud guards (defaults; per-program columns win)
    |--------------------------------------------------------------------------
    */
    'throttle' => [
        'cooldown_seconds' => 30,   // min seconds between stamps on one card
        'max_per_day' => null,      // null = unlimited stamps/day per card
    ],

    /*
    |--------------------------------------------------------------------------
    | Reward behaviour when a card completes
    |--------------------------------------------------------------------------
    | true  = reset stamp count to 0 on redemption
    | false = roll the surplus over (count - stamps_required)
    */
    'reset_on_reward' => true,

    /*
    |--------------------------------------------------------------------------
    | Stamp / reward expiry (defaults; per-program columns win)
    |--------------------------------------------------------------------------
    | Age (in days) after which the `loyalty:expire` command voids inactive
    | stamps and unredeemed earned rewards. `null` = never expires (the default,
    | matching pre-expiry behaviour). A program's `stamp_expiry_days` /
    | `reward_expiry_days` columns override these when set.
    */
    'expiry' => [
        'stamp_days' => env('LOYALTY_STAMP_EXPIRY_DAYS'),
        'reward_days' => env('LOYALTY_REWARD_EXPIRY_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP mode
    |--------------------------------------------------------------------------
    | Controls how much of the HTTP surface the package registers:
    |   'headless' - nothing; call the domain services yourself.
    |   'api'      - JSON + resource endpoints only (state, create, claim,
    |                voucher redeem, terminal stamp/redeem, wallet passes).
    |   'ui'       - everything in 'api' PLUS the shipped HTML card page and
    |                staff terminal, and publishable views/assets.
    */
    'http' => [
        'mode' => env('LOYALTY_HTTP_MODE', 'ui'),

        // Budget for the shared Core "loyalty-api" rate limiter
        // (Kurt\Modules\Core\Support\ApiRateLimiter), in
        // "maxAttempts,decayMinutes" form. Applied to the staff terminal
        // stamp/redeem endpoints as `throttle:loyalty-api`. Defaults to the
        // module's historic terminal budget so protection is unchanged.
        'rate_limit' => env('LOYALTY_HTTP_RATE_LIMIT', '60,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => 'loyalty',
        'domain' => null,
        'middleware' => ['web'],
        // Applied to public write endpoints (create / claim / voucher redeem).
        'rate_limit' => '30,1',
        // The staff terminal stamp/redeem endpoints now throttle via the shared
        // Core limiter; tune their budget with `loyalty.http.rate_limit` above.
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    | Stamp/redeem requests may carry an `Idempotency-Key` header (or
    | `idempotency_key` field). A key seen within the TTL is treated as a
    | replay: the action is not re-applied and the current state is returned.
    | Guards against double-taps / retries beyond the stamp cooldown.
    */
    'idempotency' => [
        'ttl' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff terminal
    |--------------------------------------------------------------------------
    | Middleware guarding the staff terminal. Defaults to the `loyalty:staff`
    | gate, which is undefined (deny-all) until the consuming app defines it.
    */
    'staff' => [
        'middleware' => ['can:loyalty:staff'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    | Public path the built JS/CSS are published to via
    | `php artisan vendor:publish --tag=modules-loyalty-assets`.
    */
    'assets' => [
        'base' => 'vendor/modules-loyalty',
    ],

    /*
    |--------------------------------------------------------------------------
    | Module cache convention (Core ModuleCacheFactory)
    |--------------------------------------------------------------------------
    | Config-driven cache scoped by the `loyalty` prefix, resolved via
    | Kurt\Modules\Core\Support\ModuleCacheFactory->for('loyalty'). This wraps
    | ONLY safe read-only REPORTING aggregates (the stats overview) so minor
    | staleness is acceptable and bounded by the TTL. Live wallet balances,
    | stamp counts, and any value that authorises an accrual or redemption are
    | NEVER served from this cache. `store` is selected by name (null = default
    | store) so `config:cache` stays safe.
    */
    'cache' => [
        'enabled' => (bool) env('LOYALTY_CACHE_ENABLED', true),
        'store' => env('LOYALTY_CACHE_STORE'),
        'prefix' => 'loyalty',
        'ttl' => (int) env('LOYALTY_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet passes
    |--------------------------------------------------------------------------
    | Apple + Google are opt-in and only render "Add to wallet" buttons once the
    | corresponding credentials are configured. `push` enables live pass updates
    | when a stamp is added or a reward redeemed (requires a queue worker).
    */
    'wallet' => [
        'push' => env('LOYALTY_WALLET_PUSH', false),

        'apple' => [
            'enabled' => env('LOYALTY_APPLE_WALLET', false),
            'pass_type_id' => env('LOYALTY_APPLE_PASS_TYPE_ID'),
            'team_id' => env('LOYALTY_APPLE_TEAM_ID'),
            'organization_name' => env('LOYALTY_APPLE_ORG', 'Loyalty'),
            'certificate' => env('LOYALTY_APPLE_CERTIFICATE'),
            'certificate_password' => env('LOYALTY_APPLE_CERTIFICATE_PASSWORD', ''),
            'wwdr_certificate' => env('LOYALTY_APPLE_WWDR_CERTIFICATE'),
            'icon' => env('LOYALTY_APPLE_ICON'),
            // Absolute HTTPS base Apple devices call back to; defaults to the
            // package's /apple web-service route under the app URL.
            'web_service_url' => env('LOYALTY_APPLE_WEB_SERVICE_URL'),
            // APNs (cert-based, reuses the Pass Type ID certificate above).
            'apns_host' => env('LOYALTY_APNS_HOST', 'https://api.push.apple.com'),
        ],

        'google' => [
            'enabled' => env('LOYALTY_GOOGLE_WALLET', false),
            'issuer_id' => env('LOYALTY_GOOGLE_ISSUER_ID'),
            'class_id' => env('LOYALTY_GOOGLE_CLASS_ID'),
            'service_account' => env('LOYALTY_GOOGLE_SERVICE_ACCOUNT'),
            'token_endpoint' => env('LOYALTY_GOOGLE_TOKEN_ENDPOINT', 'https://oauth2.googleapis.com/token'),
            'api_base' => env('LOYALTY_GOOGLE_API_BASE', 'https://walletobjects.googleapis.com/walletobjects/v1'),
        ],
    ],
];
