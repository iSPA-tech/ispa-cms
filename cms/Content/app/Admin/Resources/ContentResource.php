<?php

declare(strict_types=1);

namespace Cms\Content\Admin\Resources;

use App\Enums\ContentStatusEnum;
use Cms\Core\Admin\Resources\Action;
use Cms\Core\Admin\Resources\DetailView;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;

/**
 * Declarative description of the Content records admin screen (decision D-12,).
 *
 * The endpoints are the existing `ContentController` routes; the edit rules mirror `UpdateContentRequest` for the
 * fields a form can edit (name, description, video_url). The structured `content` blocks have no schema field type
 * yet, so the rich editor keeps handling them. There is deliberately no create form: a content record is created for
 * a place or an item by the place and item screens, and a record without a target cannot be listed or saved again.
 * The list shows the Vietnamese originals (the endpoint's rule); translations are reachable through the record.
 * Row actions: confirm, publish and delete, each guarded by the same ability the endpoint checks. Translation and
 * text-to-speech triggers are not declared because they need the domain token the old place screens pass.
 */
final class ContentResource extends Resource
{
    private const L = 'text.content.';

    /** Resource key. */
    public function key(): string
    {
        return 'content/contents';
    }

    /** Ability needed to view the resource; the list endpoint itself checks `content.viewAny`. */
    public function ability(): string
    {
        return 'content.viewAny';
    }

    /**
     * All fields of the resource.
     *
     * @return array<int, Field>
     */
    public function fields(): array
    {
        $statuses = array_column(ContentStatusEnum::cases(), 'value');

        return [
            Field::text('name')->label('validation.attributes.title')->required()->max(255)->searchable(),
            Field::textarea('description')->label('validation.attributes.description')->max(500),
            Field::text('video_url')->label('validation.attributes.video_url')->prebuiltRules(['nullable', 'active_url', 'max:500'], ['max' => 500]),
            Field::status('status', $statuses)
                ->label('validation.attributes.status')
                ->options(array_combine($statuses, array_map(static fn (string $status): string => self::L.'status_'.$status, $statuses)))
                ->colors([
                    'content_draft' => 'default',
                    'content_pending_translation' => 'orange',
                    'content_on_queue_translation' => 'orange',
                    'content_translated' => 'blue',
                    'content_moderated' => 'cyan',
                    'content_published' => 'green',
                    'content_rejected' => 'red',
                ])
                ->width(200)
                ->filterAllLabel(self::L.'all_statuses')
                ->filterable(),
            Field::boolean('is_confirmed')
                ->label(self::L.'col_confirmed')
                ->width(130)
                ->filterAllLabel(self::L.'all_confirmed')
                ->filterable(),
            Field::datetime('created_at')->label(self::L.'col_created')->width(160),
        ];
    }

    /** List view over `GET content`: filters, search and the camelCase paginator of `ContentCollection`. */
    public function list(): ?ListView
    {
        return ListView::make()
            ->columns(['name', 'status', 'is_confirmed', 'created_at'])
            ->search('keywords', self::L.'search_placeholder')
            ->filters(['status', 'is_confirmed'])
            ->labels([
                'title' => 'nav.a.contents',
                'subtitle' => self::L.'subtitle',
                'reload' => 'nav.b.reload',
                'empty' => self::L.'empty',
            ])
            ->rowActions(['confirm', 'publish', 'delete'])
            ->endpoint('api.v2.content.index', 'content-collection-v1')
            ->detailLink();
    }

    /** Edit form only; saving needs `content.update`. */
    public function form(): ?FormView
    {
        return FormView::make()
            ->update(['name', 'description', 'video_url'])
            ->updateEndpoint('api.v2.content.update', 'PUT')
            ->ability('content.update');
    }

    /** Detail drawer over `GET content/{uuid}` with the record history. */
    public function detail(): ?DetailView
    {
        return DetailView::make()
            ->endpoint('api.v2.content.show')
            ->labels(['title' => 'nav.a.contents'])
            ->sections(['description', 'status', 'is_confirmed', 'created_at'])
            ->timeline('content.content');
    }

    /**
     * Confirm, publish and delete; the quick route and the destroy route check the same abilities themselves.
     *
     * @return array<int, Action>
     */
    public function actions(): array
    {
        return [
            Action::make('confirm')->label(self::L.'confirm')
                ->endpoint('api.v2.content.quick', ['col' => 'is_confirmed', 'val' => true], 'PUT')
                ->visibleWhen(['field' => 'is_confirmed', 'op' => 'eq', 'value' => false])
                ->ability('content.confirm')
                ->successMessage(self::L.'confirmed')
                ->refresh('list'),
            Action::make('publish')->label(self::L.'publish')
                ->endpoint('api.v2.content.quick', ['col' => 'status', 'val' => ContentStatusEnum::CONTENT_PUBLISHED->value], 'PUT')
                ->visibleWhen(['field' => 'status', 'op' => 'ne', 'value' => ContentStatusEnum::CONTENT_PUBLISHED->value])
                ->ability('content.update')
                ->confirm(self::L.'publish_confirm')
                ->successMessage(self::L.'published')
                ->refresh('list'),
            Action::make('delete')->label('nav.b.delete')
                ->endpoint('api.v2.content.destroy', [], 'DELETE')
                ->confirm(self::L.'delete_confirm')
                ->danger()
                ->ability('content.delete')
                ->successMessage(self::L.'deleted')
                ->refresh('list'),
        ];
    }
}
