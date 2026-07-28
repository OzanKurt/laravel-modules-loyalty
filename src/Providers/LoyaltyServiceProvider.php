<?php

declare(strict_types=1);

namespace Kurt\Modules\Loyalty\Providers;

use Illuminate\Support\Facades\Event;
use Kurt\Modules\Core\Modules\ModuleManifest;
use Kurt\Modules\Core\Providers\PackageServiceProvider;
use Kurt\Modules\Core\Support\ApiRateLimiter;
use Kurt\Modules\Loyalty\Console\Commands\DemoCommand;
use Kurt\Modules\Loyalty\Console\Commands\ExpireCommand;
use Kurt\Modules\Loyalty\Console\Commands\InstallCommand;
use Kurt\Modules\Loyalty\Console\Commands\PruneVouchersCommand;
use Kurt\Modules\Loyalty\Console\Commands\StatsCommand;
use Kurt\Modules\Loyalty\Console\Commands\WalletCheckCommand;
use Kurt\Modules\Loyalty\Events\CardCompleted;
use Kurt\Modules\Loyalty\Events\RewardRedeemed;
use Kurt\Modules\Loyalty\Events\StampAdded;
use Kurt\Modules\Loyalty\Jobs\PushWalletUpdate;
use Kurt\Modules\Loyalty\Wallet\WalletManager;
use Spatie\LaravelPackageTools\Package;

final class LoyaltyServiceProvider extends PackageServiceProvider
{
    protected function module(): string
    {
        return 'loyalty';
    }

    protected function moduleManifest(): ModuleManifest
    {
        return ModuleManifest::make('loyalty')
            ->name('Loyalty')
            ->description('Digital loyalty / stamp-card system for Laravel apps (KurtModules).');
    }

    public function configurePackage(Package $package): void
    {
        // Non-HTTP concerns are always registered. The HTTP surface (routes,
        // views, assets) is registered in packageBooted() where config is
        // reliable, gated by the `loyalty.http.mode` setting.
        $package
            ->name('laravel-modules-loyalty')
            ->hasConfigFile('loyalty')
            ->hasMigrations([
                'create_loyalty_programs_table',
                'create_loyalty_cards_table',
                'create_loyalty_vouchers_table',
                'create_loyalty_stamps_table',
                'create_loyalty_redemptions_table',
                'create_loyalty_wallet_passes_table',
                'create_loyalty_wallet_registrations_table',
                'add_external_id_index_to_loyalty_wallet_passes',
                'create_loyalty_program_tiers_table',
                'add_expiry_to_loyalty_programs_and_cards',
            ])
            ->hasCommands([
                InstallCommand::class,
                DemoCommand::class,
                PruneVouchersCommand::class,
                WalletCheckCommand::class,
                StatsCommand::class,
                ExpireCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(WalletManager::class);
    }

    public function packageBooted(): void
    {
        parent::packageBooted();

        // Loaded under a clean `loyalty::` namespace (not Spatie's derived
        // `modules-loyalty::`), and available regardless of HTTP mode.
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'loyalty');
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../lang' => $this->app->langPath('vendor/loyalty'),
            ], 'modules-loyalty-translations');
        }

        $this->registerHttp();
        $this->registerWalletPush();
    }

    /**
     * Register the HTTP surface according to `loyalty.http.mode`:
     *   headless -> nothing; api -> routes only; ui -> routes + views + assets.
     */
    private function registerHttp(): void
    {
        $mode = (string) config('loyalty.http.mode', 'ui');

        if ($mode === 'headless') {
            return;
        }

        // Register the shared Core "loyalty-api" limiter before routes load so
        // `throttle:loyalty-api` (staff terminal) resolves it at request time,
        // even when the app's routes are cached.
        ApiRateLimiter::register($this->module());

        $this->loadRoutesFrom(__DIR__.'/../../routes/loyalty.php');

        if ($mode !== 'ui') {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'loyalty');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../resources/views' => resource_path('views/vendor/loyalty'),
            ], 'modules-loyalty-views');

            $this->publishes([
                __DIR__.'/../../resources/dist' => public_path('vendor/modules-loyalty'),
            ], 'modules-loyalty-assets');
        }
    }

    /**
     * Wire live wallet updates: on any card-changing event, push the current
     * state to issued passes. Opt-in via config; providers no-op until their
     * credentials + push infrastructure are configured.
     */
    private function registerWalletPush(): void
    {
        if (! (bool) config('loyalty.wallet.push', false)) {
            return;
        }

        Event::listen(
            [StampAdded::class, CardCompleted::class, RewardRedeemed::class],
            function (StampAdded|CardCompleted|RewardRedeemed $event): void {
                PushWalletUpdate::dispatch($event->card);
            },
        );
    }
}
