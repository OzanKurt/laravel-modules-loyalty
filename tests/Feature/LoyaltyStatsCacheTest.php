<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kurt\Modules\Loyalty\Enums\StampSource;
use Kurt\Modules\Loyalty\Models\Card;
use Kurt\Modules\Loyalty\Models\Program;
use Kurt\Modules\Loyalty\Services\LoyaltyStatsService;
use Kurt\Modules\Loyalty\Services\StampService;

/**
 * Seed a single program with one active card carrying a few stamps. Kept local
 * to this file (distinct name) so it does not collide with LoyaltyStatsTest's
 * global `seedStats()` helper.
 */
function seedStatsCache(int $stamps = 2): Program
{
    $program = Program::factory()->create(['stamps_required' => 10, 'cooldown_seconds' => 0]);
    $card = Card::factory()->for($program)->create();

    foreach (range(1, $stamps) as $i) {
        app(StampService::class)->add($card, source: StampSource::Manual);
    }

    return $program;
}

it('serves the overview report from cache on the second call', function () {
    seedStatsCache();

    $first = app(LoyaltyStatsService::class)->overview();

    // The second call must not touch the database: it is served from the
    // module cache populated by the first call.
    DB::enableQueryLog();
    DB::flushQueryLog();

    $second = app(LoyaltyStatsService::class)->overview();

    expect(DB::getQueryLog())->toHaveCount(0)
        ->and($second)->toEqual($first);

    DB::disableQueryLog();
});

it('bypasses the cache when loyalty.cache.enabled is false', function () {
    config()->set('loyalty.cache.enabled', false);

    $program = seedStatsCache(2);

    $before = app(LoyaltyStatsService::class)->overview();
    expect($before['totals']['stamps_granted'])->toBe(2);

    // A fresh stamp must be reflected immediately when caching is disabled.
    app(StampService::class)->add(
        Card::query()->where('program_id', $program->getKey())->firstOrFail(),
        source: StampSource::Manual,
    );

    $after = app(LoyaltyStatsService::class)->overview();

    expect($after['totals']['stamps_granted'])->toBe(3);
});

it('caches the report but never the live card balance', function () {
    $program = seedStatsCache(2);

    // Warm the report cache with caching enabled (the default).
    $cached = app(LoyaltyStatsService::class)->overview();
    expect($cached['totals']['stamps_granted'])->toBe(2);

    $card = Card::query()->where('program_id', $program->getKey())->firstOrFail();
    app(StampService::class)->add($card, source: StampSource::Manual);

    // The REPORT is intentionally stale until its TTL elapses...
    $report = app(LoyaltyStatsService::class)->overview();
    expect($report['totals']['stamps_granted'])->toBe(2);

    // ...but the live balance is always read fresh from the source of truth,
    // so authorisation/accrual paths never see a cached value.
    expect((int) $card->fresh()->stamps_count)->toBe(3);
});
