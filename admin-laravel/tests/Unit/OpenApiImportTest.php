<?php

namespace Tests\Unit;

use App\Support\OpenApiImport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OpenApiImportTest extends TestCase
{
    public function test_each_operation_becomes_a_tool(): void
    {
        $tools = OpenApiImport::tools([
            'openapi' => '3.0.3',
            'paths' => [
                '/applications/{id}' => [
                    'get' => [
                        'operationId' => 'getApplication',
                        'summary' => 'One application',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'lang', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ],
                    ],
                ],
                '/tickets' => [
                    'post' => [
                        'operationId' => 'createTicket',
                        'requestBody' => ['content' => ['application/json' => ['schema' => [
                            '$ref' => '#/components/schemas/Ticket',
                        ]]]],
                    ],
                ],
            ],
            'components' => ['schemas' => ['Ticket' => [
                'type' => 'object', 'properties' => ['subject' => ['type' => 'string']],
            ]]],
        ]);

        $this->assertCount(2, $tools);
        [$get, $post] = $tools;

        $this->assertSame('getApplication', $get['name']);
        $this->assertSame('get', $get['method']);
        $this->assertSame('/applications/{id}', $get['path']);
        $this->assertSame('One application', $get['description']);
        $this->assertFalse($get['writes']);
        $this->assertSame('path', $get['parameters']['properties']['id']['x-in']);
        $this->assertSame('query', $get['parameters']['properties']['lang']['x-in']);
        $this->assertSame(['id'], $get['parameters']['required']);

        $this->assertTrue($post['writes']);
        $this->assertSame('string', $post['parameters']['properties']['body']['properties']['subject']['type']);
    }

    public function test_missing_or_clashing_operation_ids_are_named_from_the_route(): void
    {
        $tools = OpenApiImport::tools([
            'openapi' => '3.1.0',
            'paths' => [
                '/a b/{x}' => ['get' => [], 'delete' => ['operationId' => 'same']],
                '/c' => ['put' => ['operationId' => 'same']],
            ],
        ]);

        $names = array_column($tools, 'name');
        $this->assertCount(3, $names);
        $this->assertSame($names, array_unique($names));
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $name);
        }
    }

    public function test_a_non_openapi_document_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OpenAPI 3');

        OpenApiImport::tools(['swagger' => '2.0', 'paths' => []]);
    }
}
