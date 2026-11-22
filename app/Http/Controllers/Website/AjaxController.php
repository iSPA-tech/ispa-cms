<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\Website;

use Cms\Core\Responses\AppResponse;

use Exception;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Str;
use App\Enums\StatusEnum;
use App\Enums\UserStatusEnum;
use App\Helpers\AppHelpers;
use Illuminate\Support\Facades\DB;
use App\Events\PusherContactCreate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Http\Controllers\Controller;
use Cms\Core\Events\AuditLogEvent;
use App\Events\SendConfirmToContactor;
use App\Services\SpamEmailBlocklistService;
use App\Support\ContactIntakeBridge;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Cms\Core\Http\Requests\Auth\AjaxRegisterRequest;
use Cms\Core\Interfaces\EmailInterface;
use Cms\Core\Interfaces\FieldInterface;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Content\Models\Post;
use Cms\Core\Http\Requests\Ajax\CommentVoteRequest;
use Cms\Core\Http\Requests\Ajax\GetProvincesRequest;
use App\Repositories\Interfaces\CommentInterface;
use App\Repositories\Interfaces\ContactInterface;
use App\Repositories\Interfaces\ProvinceInterface;

class AjaxController extends Controller
{
    /**
     * AjaxController constructor
     *
     * @param FieldInterface $field
     * @param ContactInterface $contact
     * @param EmailInterface $email
     * @param ProvinceInterface $province
     * @param CommentInterface $comment
     * @param UploadInterface $upload
     *
     * @author malayvuong
     * @since 6.3.8 - 2022-02-23, 11:34
     */
    public function __construct(
        protected FieldInterface    $field,
        protected ContactInterface  $contact,
        protected EmailInterface    $email,
        protected ProvinceInterface $province,
        protected CommentInterface  $comment,
        protected UploadInterface   $upload,
        protected SpamEmailBlocklistService $spamEmailBlocks,
    )
    {
        parent::__construct();
    }

    /**
     * Get provinces list by uuid
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-14, 17:28 ICT
     */
    public function getProvinces(GetProvincesRequest $request): mixed
    {
        $data = $request->validated();

        if (empty($data)) {
            return ires()->error()->message(__('mgs.form.error'));
        }

        //  Get parent data with children relationship
        $province = $this->province->getProvince($data['province']);

        if (!$province) {
            return ires()->error()->message(__('mgs.form.error'));
        }

        return ires()->data($province->children);
    }

    /**
     * Register account
     *
     * @author vuongnm
     * @since 7.160.1175 - 2023-09-19, 20:11 ICT
     */
    public function register(AjaxRegisterRequest $request): mixed
    {
        $user = new User($request->validated());

        DB::beginTransaction();
        try {
            $user->password = bcrypt($request->get('password'));
            $user->status = UserStatusEnum::USER_ACTIVE->value;
            $user->uuid = str()->uuid();
            $user->name = $request->get('first_name') . ' ' . $request->get('last_name');

            $user->save();
            //  Fire event to log
            event(new AuditLogEvent('register', [], User::class, $user->id, 'fa fa-sign-in-alt', 'text-green-600'));
            //  Send email to user to activate account
            event(new Registered($user));
        } catch (Exception $e) {
            DB::rollBack();
            Log::debug($e->getMessage(), $e->getTrace());

            return ires()->error()->message(__('mgs.register.error'));
        }
        DB::commit();

        return ires()->message(__('mgs.register.success'));
    }

    /**
     * Store comment / vote
     *
     * @author malayvuong
     * @since 6.5.5 - 2022-03-5, 13:21
     */
    public function storeComment(CommentVoteRequest $request): mixed
    {
        $data = $request;

        if (!$data->has('id')) {
            return ires()->error()->message(__('mgs.form.error'));
        }

        //  Get the field
        $fields = cache()->remember(make_key(['type' => 'form', 'form_id' => $data->get('id', 'comment')]), config('setting.cache.form', 3600), function () use ($data): mixed {
            //  Todo: change this query here fill about expires_from and expires_to
            return $this->field->findBy(['form_id' => $data->get('id', 'comment')], ['id', 'form_id', 'custom', 'styles'], [
                'items',
            ]);
        });

        if (!$fields) {
            return ires()->error()->message(__('mgs.form.error'));
        }
        DB::beginTransaction();

        try {
            //  Check old contact from data request
            $contact = $this->contact->model()->where([
                'email' => $data->get('email', $data->get('email_address')),
            ])->orWhere([
                'mobile' => $data->get('mobile', $data->get('phone_number')),
            ])->first();

            if (!$contact) {
                $contact = $this->contact->reset()->model();
            }
            $contact->fill($data->only(['organization', 'birthday', 'gender']));

            $contact->email = $data->get('email', $data->get('email_address'));
            $contact->mobile = $data->get('mobile', $data->get('phone_number'));
            $contact->name = $data->get('fullname', $data->get('name'));
            $contact->is_subscribe = true;
            $contact->source = $data->get('id', 'contact');
            $contact->type = 'visitor';
            $contact->url = request()->header('Referer');
            $contact->status = UserStatusEnum::USER_ACTIVE->value;
            if (auth()->check()) {
                $contact->user_id = user('id');
            }

            $contact->save();

            //  Store new comment
            $comment = $this->comment->model();
            $comment->target_type = $this->resolveContentTargetType((string) $data->get('type'));
            $comment->target_id = $data->get('targetId');
            $comment->name = $contact->name;
            $comment->vote = $data->get('star', 0);
            $comment->content = $data->get('content', $data->get('message'));
            $comment->contact_id = $contact->id;
            $comment->status = StatusEnum::PENDING->value;
            if (auth()->check()) {
                $comment->user_id = user('id');
            }

            $comment->save();
            //  Process to upload image
            if ($request->hasFile('images')) {
                $images = $request->file('images');
                //  Process to store image
                $disk = config('filesystems.default', 'public');
                //  Process to upload & move file
                $file = $images;
                $fileExtension = $file->getClientOriginalExtension();
                $fileSize = $file->getSize();
                $fileMimeType = $file->getMimeType();
                $fileDimension = getimagesize($file->getRealPath());
                $uploadFolder = Str::lower('image/' . now()->format('Y/m'));
                $fileName = str($file->getClientOriginalName())
                    ->replaceLast($fileExtension, '')
                    ->slug();
                $fullFileName = $fileName . '.' . $fileExtension;
                //  Make directory if not exists
                //  Explicit 0775 matches config/filesystems.php's public disk dir
                //  permission; the implicit 0755 default strips group-write, so a
                //  non-owner CLI process can't write into a dir this created.
                File::ensureDirectoryExists(Storage::disk($disk)->path($uploadFolder), 0775);
                //  Process to save file into host by Storage (Not optimized)
                Storage::disk($disk)->putFileAs($uploadFolder, $file, $fullFileName);
                //  Prepare data to save into db
                $data = [
                    'disk' => $disk,
                    'type' => 'image',
                    'name' => $fullFileName,
                    'path' => $uploadFolder,
                    'extension' => $fileExtension,
                    'file_size' => $fileSize,
                    'mime_type' => $fileMimeType,
                    'target_folder' => 'Comment',
                    'category' => 'commentImages',
                    'main' => false,
                    'dimensions' => [
                        'width' => $fileDimension[0] ?? 0,
                        'height' => $fileDimension[1] ?? 0,
                    ],
                    'config' => [
                        'requiredLogin' => false,
                        'allowComment' => false,
                        'featured' => false,
                        'showApi' => false,
                        'roles' => [],
                    ],
                    'custom' => [
                        'view' => 0,
                        'downloaded' => 0,
                        'priority' => 0,
                    ],
                ];

                $image = $this->upload->model();
                $image->fill($data);
                $image->save();

                $comment->syncImages([$image->toArray()]);
            }
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message(__('mgs.comment.error'));
        }

        DB::commit();

        return ires()->message(__('mgs.comment.success'));
    }

    protected function resolveContentTargetType(string $type): string
    {
        $type = ltrim(trim($type), '\\');

        $contentPostTypes = [
            'post',
            strtolower(Post::class),
            'app\\models\\post',
            'app\\models\\articles\\post',
        ];

        if (in_array(strtolower($type), $contentPostTypes, true)) {
            return Post::class;
        }

        if (class_exists($type)) {
            return $type;
        }

        return 'App\Models\\' . str($type)->ucfirst();
    }


    /**
     * Map search
     *
     *
     * @return mixed
     * @since 6.3.8 - 2022-02-24, 15:45
     * @author malayvuong
     */
    public function mapSearch(): mixed
    {
        $data = [];

        $listId = [];

        if (isset($data['status']) && $data['status'] === 'OK' && count($data['predictions']) > 0) {
            foreach ($data['predictions'] as $item) {
//        $global_name = '';
//        foreach ($item['address_components'] as $key => $value) {
//          if ($key > 1) {
//            break;
//          }
//          if (in_array('street_number', $value['types'], true) || in_array('route', $value['types'], true)) {
//            $global_name .= $value['long_name'];
//          } else if ($key === 0 && count($item['address_components']) < 4) {
//            $global_name .= $value['long_name'];
//          }
//
//          $global_name .= ' ';
//        }

//        $check = $this->province->findBy(['slug' => str()->slug($item['formatted_address'])], ['longitude', 'latitude', 'slug', 'id', 'name', 'global_name']);
//
//        if (!$check) {
//          $check = $this->province->reset()->model();
//          $check->uuid = $this->province->checkUnique('uuid', str()->uuid());
//          $check->uid = $this->province->checkUnique('uid', str()->random(10));
//          $check->global_name = $global_name;
//          $check->name = $item['formatted_address'];
//          $check->slug = str()->slug($item['formatted_address']);
//          $check->longitude = $item['geometry']['location']['lng'];
//          $check->latitude = $item['geometry']['location']['lat'];
//          $check->maps_id = $item['place_id'];
//
//          $check->save();
//          $check->pluck('longitude', 'latitude', 'slug', 'id', 'name', 'global_name');
//        }

//        $listId[] = $check;
                $check = $this->province->findBy(['maps_id' => $item['place_id'], 'language_id' => lang()->id], ['longitude', 'latitude', 'slug', 'id', 'name', 'global_name']);
                if ($check) {
                    $listId[] = [
                        'address' => $check->name,
                        'id_name' => $check->id_name,
                        'place_id' => null,
                    ];
                } else {
                    $listId[] = [
                        'address' => $item['description'],
                        'place_id' => $item['place_id'],
                        'id_name' => null,
                    ];
                }
            }
        }

        return ires()->data($listId);
    }

    /**
     * Store contact form
     *
     *
     * @return mixed
     * @author malayvuong
     * @since 6.3.8 - 2022-02-23, 11:24
     */
    public function storeContact(): mixed
    {
        $data = collect(request()->all());

        if (!$data->has('id')) {
            return ires()->error()->message(__('mgs.form.error'));
        }

        //  Get the field
        $fields = cache()->remember(make_key(['type' => 'form', 'form_id' => $data->get('id', 'contact')]), config('setting.cache.form', 3600), function () use ($data): mixed {
            //  Todo: change this query here fill about expires_from and expires_to
            return $this->field->findBy(['form_id' => $data->get('id', 'contact')], ['id', 'form_id', 'custom', 'styles', 'status'], [
                'items',
            ]);
        });

        if (!$fields) {
            return ires()->error()->message(__('mgs.form.error'));
        }

        $optionSources = app(\Cms\Core\Services\Forms\FormOptionResolver::class);
        $sourceSnapshot = null;
        if ($optionSources->hasSources($fields)) {
            $fields = \Cms\Core\Models\Field::query()->with('items')->where('form_id', $fields->form_id)
                ->where('status', StatusEnum::PUBLISHED->value)->firstOrFail();
            $optionSources->validateSettings($fields);
            $validated = app(\Cms\Core\Services\Forms\DynamicFormInput::class)->validate(request(), $fields);
            if ($validated['files']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['form' => 'Biểu mẫu liên hệ này chưa nhận tệp đính kèm. Vui lòng liên hệ nơi tiếp nhận.']);
            }
            $data = collect(array_merge($validated['answers'], ['id' => $fields->form_id]));
            $sourceSnapshot = $validated['snapshot'];
        }

        if ($this->spamEmailBlocks->isBlocked($data->get('email', $data->get('email_address')))) {
            return ires()->message(__('mgs.form.success'));
        }

        /** Todo: Make rule and process to validate rule */
//        foreach ($fields->items as $item) {
//        }

        DB::beginTransaction();

        try {
            //  Set new contact
            //  Check old contact from data request
            $contact = $this->contact->model()->where([
                'email' => $data->get('email', $data->get('email_address')),
            ])->orWhere([
                'mobile' => $data->get('mobile', $data->get('phone_number')),
            ])->first();

            if (!$contact) {
                $contact = $this->contact->reset()->model();
            }
            $contact->fill($data->only(['organization', 'birthday', 'gender'])->toArray());

            $contact->email = $data->get('email', $data->get('email_address'));
            $contact->mobile = $data->get('mobile', $data->get('phone_number'));
            $contact->name = $data->get('fullname', $data->get('name'));
            $contact->is_subscribe = true;
            $contact->source = $data->get('id', 'contact');
            $contact->type = 'visitor';
            $contact->url = request()->header('Referer');
            $contact->status = UserStatusEnum::USER_ACTIVE->value;
            if (auth()->check()) {
                $contact->user_id = user('id');
            }

            $contact->save();

            // Save email/contact (just save ONE object)
            $email = $this->email->model();
            $email->raw_data = $data->toArray();
            if ($sourceSnapshot) $email->raw_data = array_merge($email->raw_data, ['_form_snapshot' => $sourceSnapshot]);
            $email->content = $data->get('content', $data->get('message'));
            $email->from_name = $contact->name;
            $email->from_email = $email->reply_to = $contact->email;
            $email->subject = $data->get('subject', 'New Contact from ' . $contact->name);
            $email->contact_id = $contact->id;
            $email->type = 'inbox';
            $email->uuid = str()->uuid();
            if (auth()->check()) {
                $email->user_id = user('id');
            }

            $email->save();

            //  Pusher event
            event(new PusherContactCreate());

            //  Process send email if form has email address
            $emailAddress = '';
            foreach ($data as $key => $item) {
//                Log::debug($key.':'.(is_string($item) ? $item : json_encode($item, JSON_THROW_ON_ERROR)));
                if (is_string($item) && strpos($key, 'mail') > 0 && strpos($item, '@') > 0) {
                    $emailAddress = $item;
                    break;
                } else {
//                    Log::debug('check: '.(is_string($item) ? $item : json_encode($item, JSON_THROW_ON_ERROR)));
                }
            }
        } catch (Exception $e) {
//            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message(__('mgs.form.error'));
        }

        DB::commit();

        // Mirror into the Webmail inbox like ContactSubmissionService does (no-op when the module is absent/disabled; never throws).
        $subject = $data->get('subject');
        ContactIntakeBridge::record([
            'name' => (string) $contact->name,
            'email' => (string) $contact->email,
            'phone' => $contact->mobile,
            'subject' => is_string($subject) ? $subject : null,
            'message' => is_string($email->content) ? $email->content : '',
            'page_url' => $contact->url,
            'meta' => ['source' => $contact->source, 'context' => 'contact', 'legacy_email_id' => $email->id, 'submission_key' => $email->uuid],
        ], $contact);

        if ($emailAddress !== '' && strpos($emailAddress, '@') > 0 && filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            //  Process to send email
            event(new SendConfirmToContactor($emailAddress, Contact::class, $contact->id));
        }

        return ires()->message(__('mgs.form.success'));
    }

    /**
     * Get language list
     *
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-02-22, 10:8
     */
    public function languages()
    {
        if (!Session::has('language-locales')) {
            Session::put('language-locales', cache()->remember('language-locales', config('setting.cache.locale', 3600), function () {
                return AppHelpers::getLanguages();
            }));
        }
        $languages = Session::get('language-locales');

        return ires()->data($languages);
    }
}
