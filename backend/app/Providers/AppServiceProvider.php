<?php

namespace App\Providers;

use App\Models\User;
use App\Services\BridgeService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    private const ABILITY_LINTAS_LOKET = ['createForOtherLoket', 'updateForOtherLoket', 'viewForOtherLoket'];

    public function register(): void
    {
        $this->app->singleton(BridgeService::class, function ($app) {
            return new BridgeService(
                new Client(),
                config('services.sopp_bridge.url'),
                config('services.sopp_bridge.key'),
            );
        });
        $this->app->singleton(\App\Services\SsoTokenService::class, function ($app) {
            return new \App\Services\SsoTokenService(
                config('services.sopp_sso.key'),
                config('services.sopp_sso.aes_key'),
            );
        });
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'setoran_harian' => \App\Models\SetoranHarian::class,
            'setoran_detail' => \App\Models\SetoranDetail::class,
            'users' => \App\Models\User::class,
        ]);
        \App\Models\SetoranDetail::observe(\App\Observers\SetoranDetailObserver::class);
        // Ability-ability ini SENGAJA dikecualikan dari bypass superadmin --
        // return null di sini artinya "jangan diputus di tahap ini, lempar
        // ke Policy method yang sesuai (SetoranHarianPolicy)".
        Gate::before(function (User $user, string $ability) {
            if (in_array($ability, self::ABILITY_LINTAS_LOKET, true)) {
                return null;
            }
            if ($user->is_superadmin) {
                return true;
            }
            return null;
        });
    }
}