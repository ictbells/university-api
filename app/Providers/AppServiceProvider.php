<?php

namespace App\Providers;

use App\Models\User;
use App\Support\PortalUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $studentPortal = $notifiable instanceof User
                && $notifiable->isStudentPortalUser()
                && ! $notifiable->isStaffPortalUser();

            $front = $studentPortal
                ? PortalUrl::studentBase()
                : rtrim(PortalUrl::staff(), '/');

            return $front.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
