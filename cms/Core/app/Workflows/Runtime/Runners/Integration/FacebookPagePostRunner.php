<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

use Cms\Core\Models\Workflows\WorkflowPublishReceipt;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `facebook_page_post`: publish a text, link or photo post to a Facebook Page (Graph API). Approval is required by
 * the node definition. Idempotent by `receipt_key`: a key already posted returns the first post and posts nothing.
 *
 * Config: `credential` ($bind:<facebook_page>), `kind` (text|link|photo), `message` (template), `link` (https URL,
 * kind link), `media` (https photo URL, kind photo), `receipt_key` (template, recommended), `storeAs`.
 * Result: {posted, reused, post_id, url}.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-24
 */
class FacebookPagePostRunner extends IntegrationRunner
{
    protected function type(): string
    {
        return 'facebook_page_post';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $page = $this->bind($config['credential'] ?? null, 'facebook_page');
        $kind = (string) ($config['kind'] ?? 'text');
        $message = $this->renderText($config['message'] ?? '', $context);
        $link = $this->renderText($config['link'] ?? '', $context);
        $media = $this->renderText($config['media'] ?? '', $context);
        $receiptKey = mb_substr($this->renderText($config['receipt_key'] ?? '', $context), 0, 191);

        if (!in_array($kind, ['text', 'link', 'photo'], true)) {
            throw new IntegrationFailure('kind must be text, link or photo.');
        }

        if ($receiptKey !== '') {
            $receipt = WorkflowPublishReceipt::query()->where('receipt_key', $receiptKey)->first();
            if ($receipt !== null) {
                $result = ['posted' => true, 'reused' => true, 'post_id' => $receipt->external_id, 'url' => $receipt->external_url];
                $this->store($config, $context, $result);

                return StepRunResult::success($result, [['action' => 'facebook_page_post', 'reused' => true]]);
            }
        }

        $pageId = (string) ($page['page_id'] ?? '');
        $base = IntegrationHosts::FACEBOOK . '/' . IntegrationHosts::facebookVersion() . '/' . rawurlencode($pageId);
        $params = ['access_token' => (string) ($page['access_token'] ?? '')];

        if ($kind === 'photo') {
            if (!preg_match('#^https://#i', $media)) {
                throw new IntegrationFailure('a photo post needs media as an https URL.');
            }
            $endpoint = $base . '/photos';
            $params += ['url' => $media, 'caption' => $message];
        } else {
            if ($message === '' && $link === '') {
                throw new IntegrationFailure('nothing to post: message is empty.');
            }
            if ($kind === 'link' && !preg_match('#^https?://#i', $link)) {
                throw new IntegrationFailure('a link post needs link as an http(s) URL.');
            }
            $endpoint = $base . '/feed';
            $params += ['message' => $message] + ($kind === 'link' ? ['link' => $link] : []);
        }

        $response = $this->http()->asForm()->post($endpoint, $params);
        $this->ensureOk($response, 'Facebook post');

        $postId = (string) ($response->json('post_id') ?? $response->json('id') ?? '');
        $url = $postId !== '' ? 'https://www.facebook.com/' . rawurlencode($postId) : null;

        if ($receiptKey !== '') {
            WorkflowPublishReceipt::query()->create([
                'receipt_key' => $receiptKey,
                'integration' => 'facebook_page',
                'credential_name' => WorkflowCredentialStore::bindingName($config['credential'] ?? null),
                'external_id' => $postId,
                'external_url' => $url,
                'instance_id' => is_numeric($context->getVarPath('__runtime.instance_id')) ? (int) $context->getVarPath('__runtime.instance_id') : null,
            ]);
        }

        $result = ['posted' => true, 'reused' => false, 'post_id' => $postId, 'url' => $url];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'facebook_page_post',
            'kind' => $kind,
            'message_length' => mb_strlen($message),
        ]]);
    }
}
