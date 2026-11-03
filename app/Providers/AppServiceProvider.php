<?php

namespace App\Providers;

use App\Support\Branding;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The support contacts in every page's footer.
        View::share('support', Branding::support());
    }
}
