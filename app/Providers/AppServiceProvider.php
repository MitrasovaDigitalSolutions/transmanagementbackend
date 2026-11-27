<?php

namespace App\Providers;

use App\Models\Crew;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'vehicle' => Vehicle::class,
            'crew' => Crew::class,
        ]);
    }
}
