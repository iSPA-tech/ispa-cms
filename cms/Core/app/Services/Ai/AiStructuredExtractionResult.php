<?php

namespace Cms\Core\Services\Ai;

final class AiStructuredExtractionResult
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<int, string> $errors
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly ?array $data,
        public readonly ?float $confidence,
        public readonly array $errors,
        public readonly bool $cached,
        public readonly ?int $runId,
        public readonly string $inputHash,
        public readonly string $requestHash,
        public readonly string $schemaName,
        public readonly string $schemaVersion,
        public readonly string $model,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'status' => $this->status,
            'data' => $this->data,
            'confidence' => $this->confidence,
            'errors' => $this->errors,
            'cached' => $this->cached,
            'run_id' => $this->runId,
            'input_hash' => $this->inputHash,
            'request_hash' => $this->requestHash,
            'schema_name' => $this->schemaName,
            'schema_version' => $this->schemaVersion,
            'model' => $this->model,
            'metadata' => $this->metadata,
        ];
    }
}
