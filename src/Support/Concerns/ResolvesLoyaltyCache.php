<?php

declare(strict_types=1);

namespace Kurt\Modules\Loyalty\Support\Concerns;

use Kurt\Modules\Core\Contracts\ModuleCache;
use Kurt\Modules\Core\Support\ModuleCacheFactory;

/**
 * Resolves the Loyalty module's scoped {@see ModuleCache} once per host object.
 *
 * This cache is reserved for read-only REPORTING aggregates (e.g. the stats
 * overview). It MUST NOT wrap live balances, stamp counts, tier eligibility, or
 * any value used to authorise an accrual or redemption: those always read fresh
 * from the source of truth.
 */
trait ResolvesLoyaltyCache
{
    private ?ModuleCache $loyaltyCache = null;

    protected function loyaltyCache(): ModuleCache
    {
        return $this->loyaltyCache ??= app(ModuleCacheFactory::class)->for('loyalty');
    }
}
