<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $studentPortal = $notifiable instanceof User
                && $notifiable->isStudentPortalUser()
                && ! $notifiable->isStaffPortalUser();

            $front = rtrim(
                $studentPortal
                    ? config('app.student_url', env('STUDENT_URL', 'http://localhost:5174/student'))
                    : config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')),
                '/',
            );

            return $front.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
