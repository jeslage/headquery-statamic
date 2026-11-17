<?php

namespace Headquery\Statamic\Schema;

use Headquery\Statamic\Assets\ShallowAsset;
use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
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
            $resource = $this->blueprintResource((string) $collection->title(), $collection->entryBlueprints(), $config);
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
            $resources['taxonomy'][$taxonomy->handle()] = $this->blueprintResource(
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
            $resources['global'][$set->handle()] = [
                'title' => (string) $set->title(),
                'fields' => $this->blueprintFields($set->blueprint(), []),
            ];
        }

        foreach (Nav::all() as $nav) {
            if ($this->resourceConfig('navs', $nav->handle()) === null) {
                continue;
            }
            $navigation = [
                'title' => (string) $nav->title(),
                'fields' => $this->blueprintFields($nav->blueprint(), []),
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
            $resources['assetContainer'][$container->handle()] = $this->resource(
                (string) $container->title(),
                $container->blueprint(),
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
        return array_filter([
            'schemaVersion' => self::SCHEMA_VERSION,
            'cms' => ['name' => 'statamic', 'version' => (string) Statamic::version()],
            'generatedAt' => now()->toIso8601ZuluString(),
            'sites' => $this->sites(),
            'assetKeys' => $this->assetKeys(),
            // Empty maps must encode as {} in JSON, hence the object casts.
            'resources' => (object) array_map(function (array $byHandle) {
                ksort($byHandle);

                return (object) $byHandle;
            }, $resources),
        ], fn ($value) => $value !== []);
    }

    /**
     * Keys that asset fields and asset links carry beyond Statamic's shallow default, from
     * ShallowAsset::extend() or a project's own asset class (resolved through the container).
     */
    protected function assetKeys(): array
    {
        $keys = (array) Asset::make()->shallowAugmentedArrayKeys();

        return array_values(array_diff(array_unique(array_map('strval', $keys)), ShallowAsset::DEFAULT_KEYS));
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
     * A filterable resource with one blueprint (asset containers), plus the allowed
     * filters that are not blueprint fields as `systemFilters`.
     */
    protected function resource(string $title, ?Blueprint $blueprint, array $config): array
    {
        $fields = $this->blueprintFields($blueprint, $config);

        return $this->withSystemFilters(['title' => $title, 'fields' => $fields], array_keys((array) $fields), $config);
    }

    /**
     * A resource whose items each use one of several blueprints (collections, taxonomies).
     * The API returns only the fields of an item's own blueprint, so they are kept apart.
     */
    protected function blueprintResource(string $title, LaravelCollection $blueprints, array $config): array
    {
        $exported = [];
        $handles = [];
        foreach ($blueprints as $blueprint) {
            $fields = $this->blueprintFields($blueprint, $config);
            $exported[$blueprint->handle()] = ['title' => (string) $blueprint->title(), 'fields' => $fields];
            array_push($handles, ...array_keys((array) $fields));
        }
        ksort($exported);

        return $this->withSystemFilters(['title' => $title, 'blueprints' => (object) $exported], $handles, $config);
    }

    /** Allowed filters that are not blueprint fields, e.g. `date` or `taxonomy:tags`. */
    protected function withSystemFilters(array $resource, array $fieldHandles, array $config): array
    {
        $allowedFilters = $this->allowedFilters($config);
        if (is_array($allowedFilters)) {
            $system = array_values(array_unique(array_diff($allowedFilters, $fieldHandles)));
            sort($system);
            if ($system !== []) {
                $resource['systemFilters'] = $system;
            }
        }

        return $resource;
    }

    /** Fields of one blueprint keyed by handle, marked filterable per `allowed_filters`. */
    protected function blueprintFields(?Blueprint $blueprint, array $resourceConfig): object
    {
        if (! $blueprint) {
            return (object) [];
        }

        $allowedFilters = $this->allowedFilters($resourceConfig);
        $fields = $this->mapFields($blueprint->fields());

        foreach ($fields as $handle => &$field) {
            if ($allowedFilters === true || in_array($handle, (array) $allowedFilters, true)) {
                $field['filterable'] = true;
            }
        }
        unset($field);

        ksort($fields);

        return (object) $fields;
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
