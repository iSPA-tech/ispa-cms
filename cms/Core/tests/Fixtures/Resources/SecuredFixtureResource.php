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
 * Resource with field, form and action abilities, used by schema builder tests.
 */
final class SecuredFixtureResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'test/secured';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'secured.view';
    }

    /** Resource fields. */
    public function fields(): array
    {
        return [
            Field::text('name')->required(),
            Field::text('secret')->permission('secret.view'),
            Field::status('status', ['on', 'off']),
        ];
    }

    /** List view. */
    public function list(): ?ListView
    {
        return ListView::make()
            ->columns(['name', 'secret', 'status'])
            ->filters(['secret', 'status'])
            ->endpoint('test.secured.index', 'custom-adapter');
    }

    /** Form view guarded by an ability. */
    public function form(): ?FormView
    {
        return FormView::create(['name', 'secret'])
            ->update(['name'])
            ->endpoint('test.secured.store')
            ->updateEndpoint('test.secured.update')
            ->ability('secured.manage');
    }

    /** Detail view. */
    public function detail(): ?DetailView
    {
        return DetailView::make()
            ->endpoint('test.secured.show')
            ->sections(['name', 'secret']);
    }

    /** Actions. */
    public function actions(): array
    {
        return [
            Action::make('archive')->endpoint('test.secured.archive', [], 'DELETE')->ability('secured.manage'),
            Action::make('note')->endpoint('test.secured.note')
                ->fields([Field::text('memo'), Field::text('hidden')->permission('secret.view')]),
        ];
    }
}
