<?php

namespace App\Providers;

use App\Http\Docs\ApiOperationTransformer;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** OpenAPI documentation is generated from the controllers' PHPDoc by dedoc/scramble. */
class ApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The interactive docs and the spec are reachable everywhere except production.
        Gate::define('viewApiDocs', fn ($user = null) => ! $this->app->isProduction());

        Scramble::configure()->withOperationTransformers(new ApiOperationTransformer());
    }
}
