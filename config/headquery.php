<?php

return [

    'schema' => [

        /*
        |--------------------------------------------------------------------------
        | Schema endpoint
        |--------------------------------------------------------------------------
        |
        | Exposes the content model as headquery IR JSON so @headquery/codegen can
        | generate TypeScript types from a frontend that lives in another repo.
        |
        | The route is registered under Statamic's API prefix
        | (config('statamic.api.route'), default "api"), e.g. GET /api/headquery/schema.
        |
        | It is OFF unless enabled AND a token is set. Requests must send
        | "Authorization: Bearer <token>". Use a dedicated token: the schema
        | reveals your whole content model.
        |
        */

        'route_enabled' => env('HEADQUERY_SCHEMA_ROUTE', false),

        'token' => env('HEADQUERY_SCHEMA_TOKEN'),

        'path' => 'headquery/schema',

    ],

    /*
    |--------------------------------------------------------------------------
    | Custom field type mappings
    |--------------------------------------------------------------------------
    |
    | Map fieldtypes headquery does not know (addons, custom fieldtypes) to an
    | IR field. Unknown types are exported as { "type": "unknown" }.
    |
    | 'field_types' => [
    |     'seo_pro' => ['type' => 'object', 'fields' => ['title' => ['type' => 'string']]],
    | ],
    |
    | For dynamic mappings use FieldMapper::extend() in a service provider.
    |
    */

    'field_types' => [],

];
