<?php

declare(strict_types=1);

namespace Kurt\Modules\Loyalty\Services;

use Illuminate\Support\Carbon;
use Kurt\Modules\Loyalty\Enums\CardStatus;
use Kurt\Modules\Loyalty\Models\Card;
use Kurt\Modules\Loyalty\Models\Program;
use Kurt\Modules\Loyalty\Models\Stamp;
use Kurt\Modules\Loyalty\Support\Concerns\ResolvesLoyaltyCache;

/**
 * Aggregate loyalty metrics for reporting: card issuance, stamps granted, and
 * reward earn/redeem funnels, overall and broken down per program. All figures
 * come from a handful of grouped aggregate queries (no per-program N+1).
 *
 * These are read-only REPORTING aggregates, so the result is memoized through
 * the shared module cache scoped by the caller's report parameters. Minor
 * staleness is acceptable and bounded by `loyalty.cache.ttl`; the cache is
 * never consulted for live balances or any accrual/redemption decision.
 */
final class LoyaltyStatsService
{
    use ResolvesLoyaltyCache;

    /**
     * @return array{
     *     range: array{since: string|null, until: string|null},
     *     totals: array<string, int|float>,
     *     programs: array<int, array<string, mixed>>
     * }
     */
    public function overview(
        ?int $programId = null,
        ?Carbon $since = null,
        ?Carbon $until = null,
    ): array {
        $key = sprintf(
            'stats:overview:%s:%s:%s',
            $programId ?? 'all',
            $since?->toIso8601String() ?? 'all',
            $until?->toIso8601String() ?? 'all',
        );

        /** @var array{range: array{since: string|null, until: string|null}, totals: array<string, int|float>, programs: array<int, array<string, mixed>>} */
        return $this->loyaltyCache()->remember(
            $key,
            fn (): array => $this->computeOverview($programId, $since, $until),
        );
    }

    /**
     * @return array{
     *     range: array{since: string|null, until: string|null},
     *     totals: array<string, int|float>,
     *     programs: array<int, array<string, mixed>>
     * }
     */
    private function computeOverview(
        ?int $programId,
        ?Carbon $since,
        ?Carbon $until,
    ): array {
        $cardAgg = $this->cardAggregates($programId, $since, $until);
        $stampAgg = $this->stampAggregates($programId, $since, $until);

        $programs = Program::query()
            ->when($programId !== null, fn ($q) => $q->whereKey($programId))
            ->orderBy('id')
            ->get();

        $rows = [];
        $totals = [
            'cards_issued' => 0,
            'active_cards' => 0,
            'stamps_granted' => 0,
            'rewards_earned' => 0,
            'rewards_redeemed' => 0,
        ];

        foreach ($programs as $program) {
            $id = (int) $program->getKey();
            $card = $cardAgg[$id] ?? null;

            $row = [
                'cards_issued' => (int) ($card->cards_issued ?? 0),
                'active_cards' => (int) ($card->active_cards ?? 0),
                'stamps_granted' => (int) ($stampAgg[$id] ?? 0),
                'rewards_earned' => (int) ($card->rewards_earned ?? 0),
                'rewards_redeemed' => (int) ($card->rewards_redeemed ?? 0),
            ];

            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
            }

            $rows[] = [
                'program_id' => $id,
                'program' => $program->getTranslation('name', app()->getLocale(), false) ?: $program->slug,
                'slug' => $program->slug,
                ...$row,
                'redemption_rate' => $this->rate($row['rewards_redeemed'], $row['rewards_earned']),
            ];
        }

        return [
            'range' => [
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
            ],
            'totals' => [
                ...$totals,
                'redemption_rate' => $this->rate((int) $totals['rewards_redeemed'], (int) $totals['rewards_earned']),
            ],
            'programs' => $rows,
        ];
    }

    /**
     * Per-program card counts and reward sums, keyed by program_id.
     *
     * @return array<int, object>
     */
    private function cardAggregates(?int $programId, ?Carbon $since, ?Carbon $until): array
    {
        return Card::query()
            ->when($programId !== null, fn ($q) => $q->where('program_id', $programId))
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($until !== null, fn ($q) => $q->where('created_at', '<=', $until))
            ->groupBy('program_id')
            ->selectRaw('program_id')
            ->selectRaw('count(*) as cards_issued')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active_cards', [CardStatus::Active->value])
            ->selectRaw('sum(rewards_earned) as rewards_earned')
            ->selectRaw('sum(rewards_redeemed) as rewards_redeemed')
            ->get()
            ->keyBy('program_id')
            ->all();
    }

    /**
     * Per-program stamp counts, keyed by program_id.
     *
     * @return array<int, int>
     */
    private function stampAggregates(?int $programId, ?Carbon $since, ?Carbon $until): array
    {
        return Stamp::query()
            ->join('loyalty_cards', 'loyalty_cards.id', '=', 'loyalty_stamps.card_id')
            ->when($programId !== null, fn ($q) => $q->where('loyalty_cards.program_id', $programId))
            ->when($since !== null, fn ($q) => $q->where('loyalty_stamps.created_at', '>=', $since))
            ->when($until !== null, fn ($q) => $q->where('loyalty_stamps.created_at', '<=', $until))
            ->groupBy('loyalty_cards.program_id')
            ->selectRaw('loyalty_cards.program_id as program_id, count(*) as stamps_granted')
            ->pluck('stamps_granted', 'program_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function rate(int $redeemed, int $earned): float
    {
        return $earned > 0 ? round($redeemed / $earned, 4) : 0.0;
    }
}
