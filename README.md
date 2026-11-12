# headquery/statamic

> **Early development.** headquery is still under development and should not be used in production yet. APIs, generated types and the schema format may change in any 0.x release.

Statamic addon for [headquery](https://github.com/jeslage/headquery). It exports your
content model so `@headquery/codegen` can generate TypeScript types for
`@headquery/statamic`, the typed client for Statamic's REST Content API.

> Read-only mirror. Issues and pull requests go to the
> [headquery monorepo](https://github.com/jeslage/headquery).

## Install

```bash
composer require headquery/statamic
php artisan vendor:publish --tag=headquery-config   # optional
```

The REST API must be enabled in `config/statamic/api.php`. Only resources enabled there
are exported, and only fields in `allowed_filters` are marked filterable.

## Export via CLI (frontend in the same project)

```bash
php please headquery:schema --out=../frontend/headquery.schema.json
npx headquery generate --from ../frontend/headquery.schema.json --out ../frontend/src/cms.generated.ts
```

## Export via endpoint (headless, frontend in another repo)

```dotenv
HEADQUERY_SCHEMA_ROUTE=true
HEADQUERY_SCHEMA_TOKEN=a-long-random-token
```

```bash
npx headquery generate \
  --base-url https://cms.example.com \
  --token "$HEADQUERY_SCHEMA_TOKEN" \
  --out src/cms.generated.ts
```

The endpoint returns 404 unless both variables are set, and 401 without the right bearer
token. It sends an `ETag`, so unchanged schemas answer `304`.

## Custom fieldtypes

Unknown fieldtypes are exported as `unknown`. Map them in `config/headquery.php`
(`field_types`) or dynamically:

```php
use Headquery\Statamic\Schema\FieldMapper;

FieldMapper::extend('seo_pro', fn (array $config) => [
    'type' => 'object',
    'fields' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string']],
]);
```
