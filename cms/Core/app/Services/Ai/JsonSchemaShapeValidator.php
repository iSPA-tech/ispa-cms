<?php

namespace Cms\Core\Services\Ai;

final class JsonSchemaShapeValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param mixed $data
     * @return array<int, string>
     */
    public function validate(array $schema, mixed $data): array
    {
        return $this->validateNode($schema, $data, '$');
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $data
     * @return array<int, string>
     */
    private function validateNode(array $schema, mixed $data, string $path): array
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            return $this->validateAnyOf($schema['anyOf'], $data, $path);
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            return $this->validateAnyOf($schema['oneOf'], $data, $path);
        }

        $type = $schema['type'] ?? $this->inferType($schema);
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $candidateType) {
            if ($this->matchesType((string) $candidateType, $data)) {
                return $this->validateMatchedType((string) $candidateType, $schema, $data, $path);
            }
        }

        return [sprintf('%s must be %s.', $path, implode('|', array_map('strval', $types)))];
    }

    /**
     * @param array<int, mixed> $schemas
     * @return array<int, string>
     */
    private function validateAnyOf(array $schemas, mixed $data, string $path): array
    {
        $errors = [];

        foreach ($schemas as $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $candidateErrors = $this->validateNode($schema, $data, $path);
            if ($candidateErrors === []) {
                return [];
            }

            $errors = array_merge($errors, $candidateErrors);
        }

        return $errors === [] ? [sprintf('%s does not match any allowed schema.', $path)] : $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function validateMatchedType(string $type, array $schema, mixed $data, string $path): array
    {
        $errors = [];

        if (array_key_exists('enum', $schema) && is_array($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = sprintf('%s must be one of the allowed enum values.', $path);
        }

        if ($type === 'object') {
            return array_merge($errors, $this->validateObject($schema, $data, $path));
        }

        if ($type === 'array') {
            return array_merge($errors, $this->validateArray($schema, $data, $path));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $data
     * @return array<int, string>
     */
    private function validateObject(array $schema, mixed $data, string $path): array
    {
        if (! is_array($data) || array_is_list($data)) {
            return [sprintf('%s must be object.', $path)];
        }

        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $requiredKey) {
            if (! array_key_exists((string) $requiredKey, $data)) {
                $errors[] = sprintf('%s.%s is required.', $path, $requiredKey);
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            foreach (array_keys($data) as $key) {
                if (! array_key_exists((string) $key, $properties)) {
                    $errors[] = sprintf('%s.%s is not allowed.', $path, $key);
                }
            }
        }

        foreach ($properties as $key => $propertySchema) {
            if (! array_key_exists((string) $key, $data) || ! is_array($propertySchema)) {
                continue;
            }

            $errors = array_merge(
                $errors,
                $this->validateNode($propertySchema, $data[(string) $key], sprintf('%s.%s', $path, $key))
            );
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $data
     * @return array<int, string>
     */
    private function validateArray(array $schema, mixed $data, string $path): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            return [sprintf('%s must be array.', $path)];
        }

        $errors = [];
        $items = $schema['items'] ?? null;

        if (isset($schema['minItems']) && is_numeric($schema['minItems']) && count($data) < (int) $schema['minItems']) {
            $errors[] = sprintf('%s must contain at least %d item(s).', $path, (int) $schema['minItems']);
        }

        if (isset($schema['maxItems']) && is_numeric($schema['maxItems']) && count($data) > (int) $schema['maxItems']) {
            $errors[] = sprintf('%s must contain at most %d item(s).', $path, (int) $schema['maxItems']);
        }

        if (! is_array($items)) {
            return $errors;
        }

        foreach ($data as $index => $item) {
            $errors = array_merge($errors, $this->validateNode($items, $item, sprintf('%s[%d]', $path, $index)));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function inferType(array $schema): string
    {
        if (isset($schema['properties'])) {
            return 'object';
        }

        if (isset($schema['items'])) {
            return 'array';
        }

        return 'object';
    }

    private function matchesType(string $type, mixed $data): bool
    {
        return match ($type) {
            'object' => is_array($data) && ! array_is_list($data),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            default => true,
        };
    }
}
