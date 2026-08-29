<?php

namespace App\Providers;

use App\Listeners\PaddleEventListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionUpdated;

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

        \Laravel\Paddle\Cashier::useSubscriptionModel(\App\Models\Subscription::class);
    }
}
