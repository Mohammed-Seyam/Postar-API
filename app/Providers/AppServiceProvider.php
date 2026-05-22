<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionUpdated;
use Laravel\Paddle\Events\SubscriptionCanceled;
use App\Listeners\PaddleEventListener;

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
        Event::listen(SubscriptionCreated::class, [PaddleEventListener::class, 'handle']);
        Event::listen(SubscriptionUpdated::class, [PaddleEventListener::class, 'handle']);
        Event::listen(SubscriptionCanceled::class, [PaddleEventListener::class, 'handle']);
    }
}
