<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Fixtures\Resources;

use Cms\Core\Admin\Resources\Action;
use Cms\Core\Admin\Resources\DetailView;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;

/**
 * Pilot-like organizations resource used by resource unit tests.
 */
final class OrganizationsFixtureResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'helpdesk/organizations';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'helpdesk.organizations.view';
    }

    /** Resource fields. */
    public function fields(): array
    {
        return [
            Field::text('name')->required()->max(191)->searchable(),
            Field::email('owner_email')->required()->max(191)->onlyOn('create'),
            Field::status('status', ['active', 'suspended'])->filterable(),
            Field::enum('created_via', ['admin', 'portal']),
            Field::count('members_count'),
            Field::datetime('created_at'),
        ];
    }

    /** List view. */
    public function list(): ?ListView
    {
        return ListView::make()
            ->columns(['name', 'status', 'members_count', 'created_at'])
            ->search('q', 'helpdesk.search')
            ->filters(['status', 'created_via'])
            ->endpoint('helpdesk.organizations.index')
            ->detailLink();
    }

    /** Form view. */
    public function form(): ?FormView
    {
        return FormView::create(['name', 'owner_email'])
            ->endpoint('helpdesk.organizations.store')
            ->successMessage('helpdesk.created');
    }

    /** Detail view. */
    public function detail(): ?DetailView
    {
        return DetailView::make()
            ->endpoint('helpdesk.organizations.show')
            ->sections(['name', 'status', 'created_at'])
            ->tab('members', 'helpdesk.members', 'members-table')
            ->tab('activity', 'helpdesk.activity', 'activity-feed');
    }

    /** Actions. */
    public function actions(): array
    {
        return [
            Action::make('suspend')->label('helpdesk.suspend')
                ->endpoint('helpdesk.organizations.status', ['status' => 'suspended'])
                ->visibleWhen(['field' => 'status', 'op' => 'eq', 'value' => 'active']),
            Action::make('activate')->label('helpdesk.activate')
                ->endpoint('helpdesk.organizations.status', ['status' => 'active'])
                ->visibleWhen(['field' => 'status', 'op' => 'eq', 'value' => 'suspended']),
            Action::make('invite')->label('helpdesk.invite')
                ->endpoint('helpdesk.organizations.invite')
                ->fields([
                    Field::email('email')->required()->max(191),
                    Field::enum('role', ['owner', 'member'])->required(),
                ]),
        ];
    }

    /** Extra rule sets. */
    public function ruleSets(): array
    {
        return ['status' => [Field::status('status', ['active', 'suspended'])->required()]];
    }
}
