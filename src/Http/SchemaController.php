<?php

namespace Headquery\Statamic\Http;

use Headquery\Statamic\Schema\SchemaExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SchemaController
{
    public function __invoke(Request $request, SchemaExporter $exporter): Response
    {
        $schema = $exporter->export();

        // ETag over the content only, so codegen can skip unchanged schemas.
        $etag = '"'.sha1(json_encode(array_diff_key($schema, ['generatedAt' => true]))).'"';

        $headers = [
            'Content-Type' => 'application/json',
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ];

        if ($request->header('If-None-Match') === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response(
            json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            200,
            $headers,
        );
    }
}
