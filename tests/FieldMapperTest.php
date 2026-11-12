<?php

namespace Headquery\Statamic\Tests;

use Headquery\Statamic\Schema\FieldMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldMapperTest extends TestCase
{
    protected function tearDown(): void
    {
        FieldMapper::flushExtensions();
    }

    /** Normalise objects/arrays the way the JSON endpoint would. */
    private function map(string $type, array $config = [], ?\Closure $nested = null, array $static = []): array
    {
        $result = (new FieldMapper($static))->map($type, $config, $nested);

        return json_decode(json_encode($result), true);
    }

    public static function scalarTypes(): array
    {
        return [
            'text' => ['text', [], ['type' => 'string']],
            'text as number' => ['text', ['input_type' => 'number'], ['type' => 'number']],
            'integer' => ['integer', [], ['type' => 'number', 'integer' => true]],
            'toggle' => ['toggle', [], ['type' => 'boolean']],
            'bard' => ['bard', [], ['type' => 'richtext']],
            'date with time' => ['date', ['time_enabled' => true], ['type' => 'date', 'withTime' => true]],
            'unknown addon type' => ['seo_pro', [], ['type' => 'unknown']],
        ];
    }

    #[DataProvider('scalarTypes')]
    public function test_maps_scalar_types(string $type, array $config, array $expected): void
    {
        $this->assertSame($expected + ['cmsType' => $type], $this->map($type, $config));
    }

    public function test_required_and_instructions(): void
    {
        $field = $this->map('text', ['validate' => 'required|max:80', 'instructions' => 'Shown in lists']);

        $this->assertTrue($field['required']);
        $this->assertSame('Shown in lists', $field['description']);
    }

    public function test_select_options_in_all_formats(): void
    {
        $this->assertSame(['a', 'b'], $this->map('select', ['options' => ['a', 'b']])['values']);
        $this->assertSame(['a', 'b'], $this->map('select', ['options' => ['a' => 'A', 'b' => 'B']])['values']);
        $this->assertSame(['a'], $this->map('select', ['options' => [['key' => 'a', 'value' => 'A']]])['values']);
        $this->assertTrue($this->map('checkboxes', ['options' => ['x']])['multiple']);
    }

    public function test_relations_and_assets_respect_max_items(): void
    {
        $this->assertSame(
            ['type' => 'relation', 'to' => 'entry', 'multiple' => false, 'resources' => ['blog'], 'cmsType' => 'entries'],
            $this->map('entries', ['collections' => ['blog'], 'max_items' => 1]),
        );
        $this->assertTrue($this->map('assets')['multiple']);
        $this->assertFalse($this->map('assets', ['max_files' => 1])['multiple']);
        $this->assertTrue($this->map('assets', ['max_items' => 1])['multiple']);
    }

    public function test_nested_fields_use_callback(): void
    {
        $nested = fn (array $items) => collect($items)->mapWithKeys(fn ($i) => [$i['handle'] => ['type' => 'string']])->all();

        $grid = $this->map('grid', ['fields' => [['handle' => 'label']]], $nested);
        $this->assertSame(['type' => 'object', 'fields' => ['label' => ['type' => 'string']]], $grid['items']);

        $replicator = $this->map('replicator', [
            'sets' => ['main' => ['sets' => ['quote' => ['fields' => [['handle' => 'text']]]]]],
        ], $nested);
        $this->assertSame(['quote' => ['text' => ['type' => 'string']]], $replicator['sets']);
    }

    public function test_static_and_dynamic_extensions(): void
    {
        $this->assertSame('string', $this->map('my_type', [], null, ['my_type' => ['type' => 'string']])['type']);

        FieldMapper::extend('my_type', fn () => ['type' => 'boolean']);
        $this->assertSame('boolean', $this->map('my_type', [], null, ['my_type' => ['type' => 'string']])['type']);
    }
}
