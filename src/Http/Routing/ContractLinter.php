<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\json\JsonPointer;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use JsonSchema\Validator as JsonSchemaValidator;

/**
 * Checks the bundled document against the OpenAPI specification itself — the
 * "linter in the build" the package asks for.
 *
 * Two passes, because they catch different mistakes:
 *
 *  1. `cebe\openapi` builds its object graph and reports structural problems in
 *     its own terms (a response without a description, a malformed server URL).
 *  2. The official OpenAPI 3.0 JSON Schema, shipped with that library, is
 *     applied to the serialised document. This is the pass that notices a
 *     misspelled keyword or a value of the wrong type in a place the object
 *     model happens to tolerate.
 *
 * Without this, the first thing to notice a broken contract would be the
 * request validator at runtime — that is, in production.
 */
final class ContractLinter
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws ContractBuildError
     */
    public static function assertValid(array $document): void
    {
        $specification = self::parse($document);

        $errors = [];

        if (!$specification->validate()) {
            $errors = $specification->getErrors();
        }

        $errors = [...$errors, ...self::metaSchemaErrors($specification)];

        if ($errors !== []) {
            throw ContractBuildError::withDetails(
                'The bundled contract does not validate against OpenAPI 3.0:',
                array_values(array_unique(array_map(strval(...), $errors))),
            );
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function parse(array $document): OpenApi
    {
        try {
            $specification = new OpenApi($document);
        } catch (TypeErrorException $exception) {
            throw ContractBuildError::because(
                'The bundled contract could not be read as an OpenAPI document: ' . $exception->getMessage(),
            );
        }

        // References are internal by now (the bundler rewrote them), so the
        // resolution context needs no file to resolve against.
        $specification->setReferenceContext(new ReferenceContext($specification, '/'));
        $specification->setDocumentContext($specification, new JsonPointer(''));
        $specification->resolveReferences();

        return $specification;
    }

    /**
     * @return list<string>
     */
    private static function metaSchemaErrors(OpenApi $specification): array
    {
        if (!class_exists(JsonSchemaValidator::class)) {
            throw ContractBuildError::because(
                'justinrainbow/json-schema is not installed, so the contract cannot be checked against the '
                . 'official OpenAPI 3.0 schema. Add it to "require" in composer.json — it already ships as a '
                . 'transitive dependency of league/openapi-psr7-validator, but a direct use has to be declared.',
            );
        }

        $metaSchema = self::metaSchemaFile();

        if ($metaSchema === null) {
            throw ContractBuildError::because(
                'The OpenAPI 3.0 JSON Schema that ships with cebe-php-openapi was not found. '
                . 'Reinstall the dependencies.',
            );
        }

        $validator = new JsonSchemaValidator();
        /** @var mixed $data */
        $data = $specification->getSerializableData();
        $validator->validate($data, (object) ['$ref' => 'file://' . $metaSchema]);

        if ($validator->isValid()) {
            return [];
        }

        $errors = [];

        foreach ($validator->getErrors() as $error) {
            $property = is_string($error['property'] ?? null) ? $error['property'] : '';
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'unknown violation';

            $errors[] = $property === '' ? $message : sprintf('%s: %s', $property, $message);
        }

        return $errors;
    }

    private static function metaSchemaFile(): ?string
    {
        $reflection = new \ReflectionClass(OpenApi::class);
        $file = $reflection->getFileName();

        if ($file === false) {
            return null;
        }

        // .../src/spec/OpenApi.php -> .../schemas/openapi-v3.0.json
        $candidate = dirname($file, 3) . '/schemas/openapi-v3.0.json';

        return is_file($candidate) ? $candidate : null;
    }
}
