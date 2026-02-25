<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
// 1. استيراد الموديل والمراقب
use App\Models\Reservation;
use App\Observers\ReservationObserver;

class AppServiceProvider extends ServiceProvider
{
  
    public function register(): void
    {

    }

    public function boot(): void
    {
        Reservation::observe(ReservationObserver::class);
    }
}