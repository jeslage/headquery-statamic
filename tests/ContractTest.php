<?php

namespace Headquery\Statamic\Tests;

use Headquery\Statamic\Schema\FieldMapper;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Cross-language contract: what the PHP side emits must validate against
 * spec/ir/schema-v1.json, the same JSON Schema the JS side is tested against.
 *
 * Runs only inside the monorepo (the split Packagist repo has no spec/ folder).
 */
class ContractTest extends TestCase
{
    private const SPEC = __DIR__.'/../../../../spec';

    protected function setUp(): void
    {
        if (! is_dir(self::SPEC)) {
            $this->markTestSkipped('spec/ not available outside the monorepo');
        }
    }

    public function test_shared_fixture_validates(): void
    {
        $this->assertValid(json_decode(file_get_contents(self::SPEC.'/statamic/fixtures/schema.json')));
    }

    public function test_mapper_output_validates_for_all_known_types(): void
    {
        $mapper = new FieldMapper;
        $nested = fn (array $items) => collect($items)->mapWithKeys(fn ($i) => [$i['handle'] => $mapper->map('text', [])])->all();

        $types = [
            'text', 'textarea', 'markdown', 'integer', 'float', 'toggle', 'date', 'select', 'checkboxes',
            'entries', 'terms', 'users', 'assets', 'bard', 'replicator', 'grid', 'group', 'table', 'list', 'seo_pro',
        ];
        $config = [
            'options' => ['a', 'b'],
            'fields' => [['handle' => 'inner']],
            'sets' => ['main' => ['sets' => ['quote' => ['fields' => [['handle' => 'text']]]]]],
            'validate' => ['required'],
        ];

        $fields = [];
        foreach ($types as $type) {
            $fields[$type] = $mapper->map($type, $config, $nested);
        }
        $fields['date_range'] = $mapper->map('date', ['mode' => 'range']);

        $schema = json_decode(json_encode([
            'schemaVersion' => 1,
            'cms' => ['name' => 'statamic'],
            'resources' => ['collection' => ['test' => ['fields' => (object) $fields]]],
        ]));

        $this->assertValid($schema);
    }

    private function assertValid(mixed $data): void
    {
        $validator = new Validator;
        $validator->resolver()->registerFile('https://headquery.dev/schema/ir-v1.json', self::SPEC.'/ir/schema-v1.json');

        $result = $validator->validate($data, 'https://headquery.dev/schema/ir-v1.json');

        $this->assertTrue(
            $result->isValid(),
            $result->isValid() ? '' : json_encode((new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT),
        );
    }
}
