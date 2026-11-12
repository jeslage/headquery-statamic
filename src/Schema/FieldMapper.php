<?php

namespace Headquery\Statamic\Schema;

use Closure;

/**
 * Maps a Statamic fieldtype + its config to a headquery IR field (array).
 *
 * Deliberately free of Statamic classes so it can be unit-tested in isolation.
 * Nested fields (grid, group, replicator sets) are resolved through the $nested
 * callback, which the exporter backs with Statamic's Fields class (resolving
 * fieldset imports). Signature: fn (array $fieldItems): array<string, array>
 */
class FieldMapper
{
    /** @var array<string, Closure(array $config, ?Closure $nested): array> */
    protected static array $extensions = [];

    /**
     * @param  array<string, array>  $staticMappings  config('headquery.field_types')
     */
    public function __construct(protected array $staticMappings = [])
    {
    }

    /**
     * Register a dynamic mapping for a fieldtype (e.g. from an addon's service provider).
     *
     * @param  Closure(array $config, ?Closure $nested): array  $mapper
     */
    public static function extend(string $type, Closure $mapper): void
    {
        static::$extensions[$type] = $mapper;
    }

    public static function flushExtensions(): void
    {
        static::$extensions = [];
    }

    public function map(string $type, array $config, ?Closure $nested = null): array
    {
        $field = $this->mapType($type, $config, $nested);
        $field['cmsType'] = $type;

        if ($this->isRequired($config)) {
            $field['required'] = true;
        }

        if (! empty($config['instructions']) && is_string($config['instructions'])) {
            $field['description'] = $config['instructions'];
        }

        return $field;
    }

    protected function mapType(string $type, array $config, ?Closure $nested): array
    {
        if (isset(static::$extensions[$type])) {
            return (static::$extensions[$type])($config, $nested);
        }

        if (isset($this->staticMappings[$type])) {
            return $this->staticMappings[$type];
        }

        $single = fn (): bool => (int) ($config['max_items'] ?? 0) === 1;

        return match ($type) {
            'text' => ($config['input_type'] ?? null) === 'number' ? ['type' => 'number'] : ['type' => 'string'],
            'textarea', 'markdown', 'code', 'slug', 'color', 'link', 'video', 'time', 'template', 'html', 'icon' => ['type' => 'string'],
            'integer' => ['type' => 'number', 'integer' => true],
            'float', 'range' => ['type' => 'number'],
            'toggle' => ['type' => 'boolean'],
            'date' => $this->date($config),
            'select', 'button_group', 'radio' => $this->enum($config, (bool) ($config['multiple'] ?? false)),
            'checkboxes' => $this->enum($config, true),
            'entries' => $this->relation('entry', $config['collections'] ?? null, ! $single()),
            'terms' => $this->relation('term', $config['taxonomies'] ?? null, ! $single()),
            'users' => $this->relation('user', null, ! $single()),
            // Assets limit with `max_files`, not `max_items` like the relationship fieldtypes.
            'assets' => ['type' => 'asset', 'multiple' => (int) ($config['max_files'] ?? 0) !== 1],
            'bard' => ['type' => 'richtext'],
            'replicator' => $this->blocks($config, $nested),
            'grid' => ['type' => 'array', 'items' => $this->object($config['fields'] ?? [], $nested)],
            'group' => $this->object($config['fields'] ?? [], $nested),
            'table' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'list', 'taggable', 'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => 'unknown'],
        };
    }

    protected function date(array $config): array
    {
        $field = ['type' => 'date', 'withTime' => (bool) ($config['time_enabled'] ?? false)];

        if (($config['mode'] ?? 'single') === 'range') {
            return ['type' => 'object', 'fields' => ['start' => $field, 'end' => $field]];
        }

        return $field;
    }

    protected function enum(array $config, bool $multiple): array
    {
        $values = [];
        foreach ((array) ($config['options'] ?? []) as $key => $option) {
            // Options are either a list (['a', 'b']), a map (['a' => 'Label']),
            // or Statamic 5's list of ['key' => ..., 'value' => ...] pairs.
            if (is_array($option) && array_key_exists('key', $option)) {
                $values[] = (string) $option['key'];
            } elseif (is_int($key)) {
                $values[] = (string) $option;
            } else {
                $values[] = (string) $key;
            }
        }

        $field = ['type' => 'enum', 'values' => array_values(array_unique($values))];
        if ($multiple) {
            $field['multiple'] = true;
        }

        return $field;
    }

    protected function relation(string $to, mixed $resources, bool $multiple): array
    {
        $field = ['type' => 'relation', 'to' => $to, 'multiple' => $multiple];
        if (is_array($resources) && $resources !== []) {
            $field['resources'] = array_values(array_map('strval', $resources));
        }

        return $field;
    }

    protected function object(array $items, ?Closure $nested): array
    {
        // Cast to object so an empty map encodes as {} (not []) in JSON.
        return ['type' => 'object', 'fields' => (object) ($nested ? $nested($items) : [])];
    }

    /**
     * Replicator sets. Supports the grouped format (Statamic 4+):
     *   sets: { group: { sets: { set_handle: { fields: [...] } } } }
     * and the flat legacy format:
     *   sets: { set_handle: { fields: [...] } }
     */
    protected function blocks(array $config, ?Closure $nested): array
    {
        $sets = [];
        foreach ((array) ($config['sets'] ?? []) as $handle => $set) {
            if (isset($set['sets']) && is_array($set['sets'])) {
                foreach ($set['sets'] as $innerHandle => $inner) {
                    $sets[$innerHandle] = (object) ($nested ? $nested($inner['fields'] ?? []) : []);
                }
            } else {
                $sets[$handle] = (object) ($nested ? $nested($set['fields'] ?? []) : []);
            }
        }

        return ['type' => 'blocks', 'sets' => (object) $sets];
    }

    protected function isRequired(array $config): bool
    {
        $rules = $config['validate'] ?? [];
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        return in_array('required', (array) $rules, true);
    }
}
