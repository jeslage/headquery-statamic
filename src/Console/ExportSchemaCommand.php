<?php

namespace Headquery\Statamic\Console;

use Headquery\Statamic\Schema\SchemaExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Statamic\Console\RunsInPlease;

/**
 * php please headquery:schema [--out=path/to/schema.json] [--compact]
 */
class ExportSchemaCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:headquery:schema
        {--out= : Write to this file instead of stdout}
        {--compact : Do not pretty-print}';

    protected $description = 'Export the content model as headquery IR JSON for @headquery/codegen';

    public function handle(SchemaExporter $exporter): int
    {
        $json = $exporter->toJson(pretty: ! $this->option('compact'));

        if ($out = $this->option('out')) {
            File::ensureDirectoryExists(dirname($out));
            File::put($out, $json.PHP_EOL);
            $this->components->info("Schema written to {$out}");

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
