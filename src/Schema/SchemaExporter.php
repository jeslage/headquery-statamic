<?php

namespace Headquery\Statamic\Schema;

use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Statamic;

/**
 * Builds the headquery IR (see spec/ir/schema-v1.json) from the Statamic site.
 * Resources are grouped by kind, so handles only need to be unique per kind.
 *
 * Only resources that are enabled in config/statamic/api.php are exported, and
 * only fields listed in `allowed_filters` are marked filterable — the schema
 * mirrors what the REST API actually serves. Allowed filters that are not
 * blueprint fields (system fields like `date` or an asset's `basename`) are
 * listed in `systemFilters`. Sites are always exported (the content model
 * includes them, `site=` accepts their handles), independent of `resources.sites`.
 *
 * Uses Statamic's public API (Collection::all(), entryBlueprints(),
 * Blueprint::fields()->all(), Field::type()/config()); checked manually against
 * Statamic 6 in the playground. TODO: Testbench tests.
 */
class SchemaExporter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(protected FieldMapper $mapper)
    {
    }

    public function export(): array
    {
        $resources = ['collection' => [], 'taxonomy' => [], 'global' => [], 'navigation' => [], 'assetContainer' => []];

        if (! config('statamic.api.enabled', false)) {
            return $this->envelope($resources);
        }

        foreach (Collection::all() as $collection) {
            $config = $this->resourceConfig('collections', $collection->handle());
            if ($config === null) {
                continue;
            }
            $resource = $this->resource((string) $collection->title(), $collection->entryBlueprints(), $config);
            if ($collection->hasStructure()) {
                // Structured collections can be fetched as a page tree (GET /collections/{c}/tree).
                $structure = $collection->structure();
                $resource['structure'] = array_filter([
                    'maxDepth' => $structure->maxDepth() ? (int) $structure->maxDepth() : null,
                    'expectsRoot' => (bool) $structure->expectsRoot(),
                ], fn ($v) => $v !== null);
            }
            $resources['collection'][$collection->handle()] = $resource;
        }

        foreach (Taxonomy::all() as $taxonomy) {
            $config = $this->resourceConfig('taxonomies', $taxonomy->handle());
            if ($config === null) {
                continue;
            }
            $resources['taxonomy'][$taxonomy->handle()] = $this->resource(
                (string) $taxonomy->title(),
                $taxonomy->termBlueprints(),
                $config,
            );
        }

        foreach (GlobalSet::all() as $set) {
            $config = $this->resourceConfig('globals', $set->handle());
            if ($config === null) {
                continue;
            }
            $blueprint = $set->blueprint();
            $resources['global'][$set->handle()] = [
                'title' => (string) $set->title(),
                'fields' => $this->mergeBlueprints($blueprint ? collect([$blueprint]) : collect(), []),
            ];
        }

        foreach (Nav::all() as $nav) {
            if ($this->resourceConfig('navs', $nav->handle()) === null) {
                continue;
            }
            $blueprint = $nav->blueprint();
            $navigation = [
                'title' => (string) $nav->title(),
                'fields' => $this->mergeBlueprints($blueprint ? collect([$blueprint]) : collect(), []),
                // Entries of these collections can be linked as pages; the API returns their fields too.
                'collections' => $nav->collections()->map->handle()->sort()->values()->all(),
            ];
            if ($nav->maxDepth()) {
                $navigation['maxDepth'] = (int) $nav->maxDepth();
            }
            $resources['navigation'][$nav->handle()] = $navigation;
        }

        foreach (AssetContainer::all() as $container) {
            $config = $this->resourceConfig('assets', $container->handle());
            if ($config === null) {
                continue;
            }
            $blueprint = $container->blueprint();
            $resources['assetContainer'][$container->handle()] = $this->resource(
                (string) $container->title(),
                $blueprint ? collect([$blueprint]) : collect(),
                $config,
            );
        }

        return $this->envelope($resources);
    }

    public function toJson(bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($this->export(), $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    protected function envelope(array $resources): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'cms' => ['name' => 'statamic', 'version' => (string) Statamic::version()],
            'generatedAt' => now()->toIso8601ZuluString(),
            'sites' => $this->sites(),
            // Empty maps must encode as {} in JSON, hence the object casts.
            'resources' => (object) array_map(function (array $byHandle) {
                ksort($byHandle);

                return (object) $byHandle;
            }, $resources),
        ];
    }

    /**
     * Sites keyed by handle, in config order (the first is the default site).
     * Single-site installs export their one site.
     */
    protected function sites(): object
    {
        return (object) Site::all()
            ->mapWithKeys(fn ($site) => [
                $site->handle() => ['name' => (string) $site->name(), 'locale' => (string) $site->locale()],
            ])
            ->all();
    }

    /**
     * A filterable resource: merged blueprint fields, plus the allowed filters
     * that are not blueprint fields as `systemFilters`.
     */
    protected function resource(string $title, LaravelCollection $blueprints, array $config): array
    {
        $fields = $this->mergeBlueprints($blueprints, $config);
        $resource = ['title' => $title, 'fields' => $fields];

        $allowedFilters = $this->allowedFilters($config);
        if (is_array($allowedFilters)) {
            $system = array_values(array_unique(array_diff($allowedFilters, array_keys((array) $fields))));
            sort($system);
            if ($system !== []) {
                $resource['systemFilters'] = $system;
            }
        }

        return $resource;
    }

    /**
     * Union of all blueprints of a resource. A field that only exists in some
     * blueprints cannot be required.
     */
    protected function mergeBlueprints(LaravelCollection $blueprints, array $resourceConfig): object
    {
        $allowedFilters = $this->allowedFilters($resourceConfig);
        $merged = [];
        $seenIn = [];

        foreach ($blueprints as $blueprint) {
            foreach ($this->mapFields($blueprint->fields()) as $handle => $field) {
                $merged[$handle] ??= $field;
                $seenIn[$handle] = ($seenIn[$handle] ?? 0) + 1;
            }
        }

        $count = $blueprints->count();
        foreach ($merged as $handle => &$field) {
            if ($seenIn[$handle] < $count) {
                unset($field['required']);
            }
            if ($allowedFilters === true || in_array($handle, (array) $allowedFilters, true)) {
                $field['filterable'] = true;
            }
        }
        unset($field);

        ksort($merged);

        return (object) $merged;
    }

    /** @return array<string, array> */
    protected function mapFields(Fields $fields): array
    {
        $nested = fn (array $items): array => $this->mapFields(new Fields($items));

        return $fields->all()
            ->mapWithKeys(fn (Field $field) => [
                $field->handle() => $this->mapper->map($field->type(), $field->config(), $nested),
            ])
            ->all();
    }

    /**
     * Resolve config('statamic.api.resources.<type>') for one handle.
     * Returns null when the resource is not exposed by the API.
     *
     * Supported shapes: true | false | ['*' => ...] | ['handle' => true|array]
     */
    protected function resourceConfig(string $type, string $handle): ?array
    {
        $setting = config("statamic.api.resources.{$type}", false);

        if ($setting === true) {
            return [];
        }
        if (! is_array($setting)) {
            return null;
        }

        $entry = $setting[$handle] ?? $setting['*'] ?? null;
        if ($entry === null || $entry === false) {
            return null;
        }

        return is_array($entry) ? $entry : [];
    }

    /** @return true|string[] true means "all fields" */
    protected function allowedFilters(array $resourceConfig): bool|array
    {
        $filters = $resourceConfig['allowed_filters'] ?? [];

        if ($filters === true || $filters === ['*']) {
            return true;
        }

        return is_array($filters) ? $filters : [];
    }
}
