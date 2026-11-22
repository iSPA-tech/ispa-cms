<?php

namespace Cms\Core\Tests\Unit\Services\Import;

use Cms\Core\Services\Import\ImportContext;
use Cms\Core\Services\Import\ImportDefinitionInterface;
use Cms\Core\Services\Import\ImportValidationContext;
use Cms\Core\Services\Import\ResolvedRow;
use Illuminate\Database\Eloquent\Model;

class PestTestImportDefinition implements ImportDefinitionInterface
{
    public function modelClass(): string
    {
        return PestTestImportModel::class;
    }

    public function fillable(): array
    {
        return ['name', 'email', 'age'];
    }

    public function uniqueBy(): array
    {
        return ['email'];
    }

    public function uniqueSets(): ?array
    {
        return null;
    }

    public function casts(): array
    {
        return ['age' => 'int'];
    }

    public function defaults(): array
    {
        return [];
    }

    public function transformers(): array
    {
        return [];
    }

    public function resolveRow(array $raw, ImportContext $ctx): ResolvedRow
    {
        return new ResolvedRow($raw, 0);
    }

    public function rules(ImportValidationContext $ctx): array
    {
        return ['email' => 'required|email'];
    }

    public function beforeValidate(ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function afterValidate(ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function beforeUpsert(ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function afterUpsert(Model $model, ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function handlePivotRelations(ResolvedRow $row, Model $model, ImportContext $ctx): void
    {
    }

    public function templateKey(): string
    {
        return 'tests/pest-import-models';
    }

    public function templateHeaders(): array
    {
        return ['name', 'email', 'age'];
    }

    public function templateNotes(): array
    {
        return [];
    }

    public function inputMappings(): array
    {
        return [];
    }
}
