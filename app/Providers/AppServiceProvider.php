<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

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
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureErrorPages();
    }

    /**
     * Show the friendly error page for the errors people can meet. A real fault (500) keeps Laravel's detailed screen
     * while developing, and scanner or search requests that expect JSON still get a plain JSON answer.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            $request = $response->request;

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return null;
            }

            $status = $response->statusCode();

            if (! in_array($status, [403, 404, 419, 429, 500, 503], true) || ($status === 500 && config('app.debug'))) {
                return null;
            }

            return $response->render('error', ['status' => $status])->withSharedData();
        });
    }

    /**
     * Limits for the endpoints that hand out resident details, so they cannot be used to list everyone. A person
     * scanning or searching by hand stays far below them.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('scan', fn (Request $request): Limit => Limit::perMinute(60)->by('scan|'.$request->user()->id));
        RateLimiter::for('lookup', fn (Request $request): Limit => Limit::perMinute(120)->by('lookup|'.$request->user()->id));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
