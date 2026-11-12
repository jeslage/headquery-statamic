<?php

namespace Headquery\Statamic;

use Headquery\Statamic\Console\ExportSchemaCommand;
use Headquery\Statamic\Http\Middleware\AuthorizeSchemaRequest;
use Headquery\Statamic\Http\SchemaController;
use Headquery\Statamic\Schema\FieldMapper;
use Headquery\Statamic\Schema\SchemaExporter;
use Illuminate\Support\Facades\Route;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        ExportSchemaCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/headquery.php', 'headquery');

        $this->app->singleton(FieldMapper::class, fn () => new FieldMapper(
            (array) config('headquery.field_types', [])
        ));

        $this->app->singleton(SchemaExporter::class);
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/headquery.php' => config_path('headquery.php'),
        ], 'headquery-config');

        $this->registerSchemaRoute();
    }

    protected function registerSchemaRoute(): void
    {
        if (! config('headquery.schema.route_enabled')) {
            return;
        }

        $prefix = trim((string) config('statamic.api.route', 'api'), '/');
        $path = trim((string) config('headquery.schema.path', 'headquery/schema'), '/');

        Route::get("{$prefix}/{$path}", SchemaController::class)
            ->middleware(AuthorizeSchemaRequest::class)
            ->name('headquery.schema');
    }
}
