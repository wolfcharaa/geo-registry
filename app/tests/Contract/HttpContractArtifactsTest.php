<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function count;
use function file_get_contents;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_match;

use RuntimeException;

use function sort;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtoupper;
use function substr;

use Symfony\Component\Yaml\Yaml;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpContractArtifactsTest
{
    private const ROOT = __DIR__ . '/../../..';
    private const OPENAPI = self::ROOT . '/docs/api/openapi.yaml';
    private const COLLECTION = self::ROOT . '/docs/api/postman/geo-registry.postman_collection.json';
    private const ENVIRONMENT = self::ROOT . '/docs/api/postman/geo-registry.local.postman_environment.json';
    private const BASELINE = self::ROOT . '/docs/api/http-contract-baseline.md';

    /** @var list<string> */
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    public function testOpenApiOperationsAreCoveredByPostmanRequests(): void
    {
        $openApi = self::openApi();
        Assert::same($openApi['openapi'] ?? null, '3.1.0');

        $schemas = self::arrayField(self::arrayField($openApi, 'components'), 'schemas');
        self::assertSchemaReferencesExist($openApi, $schemas);

        $operations = self::operations($openApi);
        $collection = self::jsonObject(self::COLLECTION);
        $info = self::arrayField($collection, 'info');
        Assert::same(
            $info['schema'] ?? null,
            'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        );

        $requests = self::requests(self::listField($collection, 'item'));
        $coverage = [];
        $locations = [];
        $diagnostics = 0;

        foreach ($requests as $request) {
            $operationId = self::operationIdFromDescription($request['description']);
            if ($operationId === null) {
                ++$diagnostics;
                Assert::same($request['folder'], 'Diagnostics');
                continue;
            }

            $operationKey = self::operationKey($operationId);
            Assert::true(array_key_exists($operationKey, $operations));
            $operation = $operations[$operationKey];
            Assert::same($request['method'], $operation['method']);
            Assert::same($request['path'], $operation['path']);

            $coverage[$operationKey] = true;
            $locations[$request['folder'] . ' / ' . $request['name']] = $operationId;
        }

        Assert::same($diagnostics, 1);

        $operationIds = array_keys($operations);
        $coveredOperationIds = array_keys($coverage);
        sort($operationIds);
        sort($coveredOperationIds);
        Assert::same($coveredOperationIds, $operationIds);

        foreach ($operations as $operation) {
            $primaryLocation = $operation['postmanFolder'] . ' / ' . $operation['postmanRequest'];
            Assert::same($locations[$primaryLocation] ?? null, $operation['operationId']);
        }
    }

    public function testExamplesAreJsonAndContainOnlySyntheticValues(): void
    {
        $collection = self::jsonObject(self::COLLECTION);
        $requests = self::requests(self::listField($collection, 'item'));
        $savedResponses = 0;

        foreach ($requests as $request) {
            foreach ($request['responses'] as $response) {
                if (!is_array($response)) {
                    throw new RuntimeException('Postman response must be an object.');
                }

                $body = $response['body'] ?? null;
                if (!is_string($body) || $body === '') {
                    continue;
                }

                json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                ++$savedResponses;
            }
        }

        Assert::true($savedResponses >= 8);

        $environment = self::jsonObject(self::ENVIRONMENT);
        $environmentValues = [];
        foreach (self::listField($environment, 'values') as $variable) {
            $variable = self::object($variable, 'Postman environment variable');

            $key = self::stringField($variable, 'key');
            $environmentValues[$key] = self::stringField($variable, 'value');
            Assert::false((bool) preg_match('/(?:password|secret|token|authorization|api[_-]?key)/i', $key));
        }

        Assert::same($environmentValues['baseUrl'] ?? null, 'http://127.0.0.1:8080');
        Assert::same($environmentValues['addressId'] ?? null, '00000000-0000-4000-8000-000000770005');

        $artifacts = implode("\n", [
            self::read(self::OPENAPI),
            self::read(self::COLLECTION),
            self::read(self::ENVIRONMENT),
            self::read(self::BASELINE),
        ]);

        foreach ([
            'Москва',
            'Арбат',
            'Тверская',
            '1405113',
            '95251254',
            '80329459',
            '55.7558',
            '37.6173',
            '127.0.0.1:8899',
        ] as $forbiddenValue) {
            Assert::false(str_contains($artifacts, $forbiddenValue));
        }
    }

    /** @return array<array-key, mixed> */
    private static function openApi(): array
    {
        $openApi = Yaml::parseFile(self::OPENAPI);

        return self::object($openApi, 'OpenAPI document');
    }

    /**
     * @param array<array-key, mixed> $openApi
     * @return array<array-key, array{
     *     operationId: string,
     *     method: string,
     *     path: string,
     *     postmanFolder: string,
     *     postmanRequest: string
     * }>
     */
    private static function operations(array $openApi): array
    {
        $operations = [];

        foreach (self::arrayField($openApi, 'paths') as $path => $pathItem) {
            if (!is_string($path)) {
                throw new RuntimeException('Every OpenAPI path item must be an object.');
            }
            $pathItem = self::object($pathItem, 'OpenAPI path item');

            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem[$method] ?? null;
                if ($operation === null) {
                    continue;
                }
                $operation = self::object($operation, 'OpenAPI operation');

                $operationId = self::identifierField($operation, 'operationId');
                $operationKey = self::operationKey($operationId);
                if (array_key_exists($operationKey, $operations)) {
                    throw new RuntimeException('Duplicate OpenAPI operationId: ' . $operationId);
                }

                $migrationStatus = self::stringField($operation, 'x-migration-status');
                Assert::true($migrationStatus === 'current' || $migrationStatus === 'planned');
                self::assertJsonResponsesAreDocumented($operation);

                $operations[$operationKey] = [
                    'operationId' => $operationId,
                    'method' => strtoupper($method),
                    'path' => $path,
                    'postmanFolder' => self::stringField($operation, 'x-postman-folder'),
                    'postmanRequest' => self::stringField($operation, 'x-postman-request'),
                ];
            }
        }

        Assert::same(count($operations), 7);

        return $operations;
    }

    /** @param array<array-key, mixed> $operation */
    private static function assertJsonResponsesAreDocumented(array $operation): void
    {
        $responses = self::arrayField($operation, 'responses');
        Assert::true($responses !== []);

        foreach ($responses as $response) {
            $response = self::object($response, 'OpenAPI response');

            $content = self::arrayField($response, 'content');
            $json = self::arrayField($content, 'application/json');
            Assert::true(self::arrayField($json, 'schema') !== []);
            Assert::true(self::arrayField($json, 'examples') !== []);
        }
    }

    /**
     * @param list<mixed> $items
     * @param list<string> $parents
     * @return list<array{
     *     folder: string,
     *     name: string,
     *     description: string,
     *     method: string,
     *     path: string,
     *     responses: list<mixed>
     * }>
     */
    private static function requests(array $items, array $parents = []): array
    {
        $requests = [];

        foreach ($items as $item) {
            $item = self::object($item, 'Postman item');

            $name = self::stringField($item, 'name');
            $children = $item['item'] ?? null;
            if (is_array($children)) {
                $requests = array_merge(
                    $requests,
                    self::requests(self::list($children, 'Postman child items'), [...$parents, $name]),
                );
                continue;
            }

            $request = self::arrayField($item, 'request');
            $url = self::stringField($request, 'url');
            $path = parse_url(str_replace('{{baseUrl}}', 'http://contract.local', $url), PHP_URL_PATH);
            if (!is_string($path)) {
                throw new RuntimeException('Postman request URL must have a path.');
            }

            $requests[] = [
                'folder' => implode(' / ', $parents),
                'name' => $name,
                'description' => self::stringField($item, 'description'),
                'method' => strtoupper(self::stringField($request, 'method')),
                'path' => $path,
                'responses' => self::listField($item, 'response'),
            ];
        }

        return $requests;
    }

    private static function operationIdFromDescription(string $description): ?string
    {
        $matches = [];
        if (preg_match('/^OpenAPI operationId: ([A-Za-z][A-Za-z0-9]*)/m', $description, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private static function operationKey(string $operationId): string
    {
        return 'operation:' . $operationId;
    }

    /** @param array<array-key, mixed> $schemas */
    private static function assertSchemaReferencesExist(mixed $value, array $schemas): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if ($key === '$ref' && is_string($child) && str_starts_with($child, '#/components/schemas/')) {
                $schema = substr($child, 21);
                Assert::true($schema !== '' && array_key_exists($schema, $schemas));
                continue;
            }

            self::assertSchemaReferencesExist($child, $schemas);
        }
    }

    /** @return array<array-key, mixed> */
    private static function jsonObject(string $path): array
    {
        $value = json_decode(self::read($path), true, flags: JSON_THROW_ON_ERROR);

        return self::object($value, $path);
    }

    /** @param array<array-key, mixed> $value */
    private static function stringField(array $value, string $field): string
    {
        $result = $value[$field] ?? null;
        if (!is_string($result) || $result === '') {
            throw new RuntimeException('Expected non-empty string field: ' . $field);
        }

        return $result;
    }

    /** @param array<array-key, mixed> $value */
    private static function identifierField(array $value, string $field): string
    {
        $result = self::stringField($value, $field);
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $result) !== 1) {
            throw new RuntimeException('Expected identifier field: ' . $field);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function arrayField(array $value, string $field): array
    {
        $result = $value[$field] ?? null;

        return self::object($result, 'object field ' . $field);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return list<mixed>
     */
    private static function listField(array $value, string $field): array
    {
        $result = $value[$field] ?? null;

        return self::list($result, 'list field ' . $field);
    }

    /** @return array<array-key, mixed> */
    private static function object(mixed $value, string $context): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Expected object: ' . $context);
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Expected list: ' . $context);
        }

        return $value;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read: ' . $path);
        }

        return $contents;
    }
}
