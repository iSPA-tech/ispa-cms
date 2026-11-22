<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services\Import;

use App\Models\Contact;
use Cms\Core\Services\Import\Concerns\HasTemplateMetadata;
use Cms\Core\Services\Import\ImportContext;
use Cms\Core\Services\Import\ImportDefinitionInterface;
use Cms\Core\Services\Import\ImportValidationContext;
use Cms\Core\Services\Import\ResolvedRow;
use Illuminate\Database\Eloquent\Model;

/**
 * Import definition for Contact model.
 *
 * Supports CSV/Excel import with field mapping for contacts.
 */
class ContactImportDefinition implements ImportDefinitionInterface
{
    use HasTemplateMetadata;

    /**
     * {@inheritDoc}
     */
    public function modelClass(): string
    {
        return Contact::class;
    }

    /**
     * {@inheritDoc}
     */
    public function fillable(): array
    {
        return [
            'name',
            'email',
            'mobile',
            'address',
            'organization',
            'url',
            'source',
            'type',
            'birthday',
            'gender',
            'is_subscribe',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function uniqueBy(): array
    {
        return ['email'];
    }

    /**
     * {@inheritDoc}
     */
    public function uniqueSets(): ?array
    {
        return [
            ['email'],
            ['name', 'mobile'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function casts(): array
    {
        return [
            'birthday'     => 'date',
            'gender'       => 'integer',
            'is_subscribe' => 'boolean',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function defaults(): array
    {
        return [
            'gender'       => 0,
            'is_subscribe' => false,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function transformers(): array
    {
        return [
            'is_subscribe' => static function ($value) {
                if (is_bool($value)) {
                    return $value;
                }

                $truthy = ['1', 'true', 'yes', 'y', 'on'];

                return in_array(strtolower(trim((string) $value)), $truthy, true);
            },
            'gender' => static function ($value) {
                $map = [
                    'male'    => 1,
                    'female'  => 2,
                    'nam'     => 1,
                    'nữ'      => 2,
                    'nu'      => 2,
                    '1'       => 1,
                    '2'       => 2,
                ];

                return $map[strtolower(trim((string) $value))] ?? 0;
            },
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function resolveRow(array $raw, ImportContext $ctx): ResolvedRow
    {
        return new ResolvedRow($raw, 0);
    }

    /**
     * {@inheritDoc}
     */
    public function rules(ImportValidationContext $ctx): array
    {
        return [
            'name'         => 'required|string|max:255',
            'email'        => 'nullable|email|max:255',
            'mobile'       => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:500',
            'organization' => 'nullable|string|max:100',
            'url'          => 'nullable|string|max:1000',
            'source'       => 'nullable|string|max:50',
            'type'         => 'nullable|string|max:20',
            'birthday'     => 'nullable|date',
            'gender'       => 'nullable|integer|in:0,1,2',
            'is_subscribe' => 'nullable|boolean',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function beforeValidate(ResolvedRow $row, ImportContext $ctx): void {}

    /**
     * {@inheritDoc}
     */
    public function afterValidate(ResolvedRow $row, ImportContext $ctx): void {}

    /**
     * {@inheritDoc}
     */
    public function beforeUpsert(ResolvedRow $row, ImportContext $ctx): void {}

    /**
     * {@inheritDoc}
     */
    public function afterUpsert(Model $model, ResolvedRow $row, ImportContext $ctx): void {}

    /**
     * {@inheritDoc}
     */
    public function handlePivotRelations(ResolvedRow $row, Model $model, ImportContext $ctx): void {}

    /**
     * {@inheritDoc}
     */
    public function templateKey(): string
    {
        return 'App/contacts';
    }

    /**
     * {@inheritDoc}
     */
    public function templateHeaders(): array
    {
        return [
            'name',
            'email',
            'mobile',
            'address',
            'organization',
            'url',
            'source',
            'type',
            'birthday',
            'gender',
            'is_subscribe',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function templateNotes(): array
    {
        return [
            'name'         => 'Required - Contact name',
            'email'        => 'Email address (used as unique identifier for upsert)',
            'mobile'       => 'Phone number',
            'address'      => 'Full address',
            'organization' => 'Company/organization name',
            'url'          => 'Website URL',
            'source'       => 'Lead source (e.g., website, referral, social)',
            'type'         => 'Contact type (e.g., customer, partner, lead)',
            'birthday'     => 'Date of birth - YYYY-MM-DD or DD/MM/YYYY',
            'gender'       => '0 = Unknown, 1 = Male, 2 = Female (or: male, female, nam, nữ)',
            'is_subscribe' => '1/true/yes = subscribed, 0/false/no = not subscribed',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function inputMappings(): array
    {
        return [];
    }
}
