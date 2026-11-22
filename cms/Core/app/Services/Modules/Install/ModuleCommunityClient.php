<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Models\User;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Closure;

/**
 * Ratings, questions and private feedback of hub modules for the site admin.
 *
 * Talks to the hub community endpoints of (`/api/v2/public/platform/ratings|questions|feedback/{name}`)
 * through PlatformHubClient only, so every request is signed with the domain secret and every answer is verified.
 *
 * Who wrote it: the hub receives a pseudonymous reference and a display name, nothing else about the person.
 * - `author_ref = hash_hmac('sha256', user uuid, CMS_DOMAIN_SECRET)`: stable for one user on one site (the hub can
 *   keep one rating per site and spot repeated posts), different on every other site (another secret), and it cannot
 *   be turned back into the uuid without the secret. The e-mail, the numeric id, the uuid and the browser IP are
 *   never sent.
 * - `author_name` is what the Webmaster typed, defaulting to their given name ({@see defaultName()}). A name that
 *   pretends to be iSPA or holds an e-mail address or a phone number is refused with a plain message
 *   ({@see nameProblem()}), never masked; the hub refuses the same names again.
 *
 * Reads are validated field by field (capped strings, star counts 1-5), so the admin never renders unchecked hub
 * text, and never carry the hub's author references. A hub that predates the community endpoints (404 on a read)
 * answers `available = false`, so the drawer simply leaves the section out.
 */
final class ModuleCommunityClient
{
    /** Error code: the display name pretends to be iSPA. */
    public const NAME_ISPA = 'MODULE_COMMUNITY_NAME_ISPA';

    /** Error code: the display name holds an e-mail address or a phone number. */
    public const NAME_CONTACT = 'MODULE_COMMUNITY_NAME_CONTACT';

    /** Hub path prefix of the community endpoints. */
    public const HUB_PATH = '/api/v2/public/platform/';

    /** The hub does not see the module installed and enabled in this site's latest status report (at most 7 days old). */
    public const REASON_INSTALL_REQUIRED = 'MODULE_COMMUNITY_INSTALL_REQUIRED';

    /** Feedback kinds the hub accepts. */
    public const FEEDBACK_KINDS = ['feature', 'praise', 'other'];

    /** Longest rating text and question. */
    public const MAX_BODY = 2000;

    /** Longest feedback message. */
    public const MAX_FEEDBACK_BODY = 4000;

    /** Longest display name. */
    public const MAX_NAME = 120;

    /** Display name used when the account has no usable name. */
    public const FALLBACK_NAME = 'Webmaster';

    /** Answer kinds a question or answer may carry. */
    private const AUTHOR_KINDS = ['site', 'publisher', 'ispa'];

    /** @var (Closure(): PlatformHubClient)|null */
    private ?Closure $clientFactory;

    /**
     * @param (callable(): PlatformHubClient)|null $clientFactory builds the hub client lazily (default: from config)
     */
    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory !== null ? Closure::fromCallable($clientFactory) : null;
    }

    /**
     * The pseudonymous reference of a user on the site whose domain secret is given (64 lower-case hex characters).
     */
    public static function authorRef(string $userUuid, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $userUuid, $secret);
    }

    /**
     * The default display name: the given name (the last word of a Vietnamese full name, "Trần Thị Lan" ->
     * "Lan"), or "Webmaster" when the name is empty or looks like an e-mail address.
     */
    public static function defaultName(?string $fullName): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $fullName));
        if ($name === '' || str_contains($name, '@')) {
            return self::FALLBACK_NAME;
        }
        $words = explode(' ', $name);
        $given = (string) end($words);

        return mb_substr($given, 0, self::MAX_NAME);
    }

    /**
     * The problem of a typed display name: {@see NAME_ISPA}, {@see NAME_CONTACT} or null when it is fine. The hub's
     * own check counts; this one only gives the Webmaster a friendly message before anything leaves the site.
     */
    public static function nameProblem(?string $name): ?string
    {
        $name = (string) $name;
        $plain = strtr(mb_strtolower($name), ['đ' => 'd']);
        if (class_exists(\Normalizer::class)) {
            $plain = (string) \Normalizer::normalize($plain, \Normalizer::FORM_D);
        }
        $plain = (string) preg_replace('/[^a-z0-9]+/', '', (string) preg_replace('/\p{Mn}+/u', '', $plain));
        if (str_contains($plain, 'ispa')) {
            return self::NAME_ISPA;
        }
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $name) === 1 || preg_match('/\+?\d(?:[\s().\-]?\d){6,}/u', $name) === 1) {
            return self::NAME_CONTACT;
        }

        return null;
    }

    /**
     * This site's rating of the module and whether the hub lets it rate now.
     *
     * @return array{available: bool, rating: array<string, mixed>|null, can_rate: bool, reason: string|null}
     *
     * @throws PlatformHubException any hub failure other than "not found"
     */
    public function mine(string $name): array
    {
        $payload = $this->read('ratings/' . $name . '/mine');
        if ($payload === null) {
            return ['available' => false, 'rating' => null, 'can_rate' => false, 'reason' => null];
        }
        $rating = is_array($payload['rating'] ?? null) ? self::ratingItem($payload['rating']) : null;
        if ($rating !== null) {
            $rating['hidden'] = ($payload['rating']['hidden'] ?? false) === true;
        }
        $canRate = ($payload['can_rate'] ?? false) === true;

        return [
            'available' => true,
            'rating' => $rating,
            'can_rate' => $canRate,
            'reason' => $canRate ? null : self::REASON_INSTALL_REQUIRED,
        ];
    }

    /**
     * One page of the module's visible ratings with the aggregate.
     *
     * @return array<string, mixed>
     *
     * @throws PlatformHubException any hub failure other than "not found"
     */
    public function ratings(string $name, int $page): array
    {
        $payload = $this->read('ratings/' . $name, ['page' => $page]);
        if ($payload === null) {
            return ['available' => false, 'average' => null, 'count' => 0, 'items' => []] + self::paging([], $page);
        }
        $items = [];
        foreach (array_slice(array_values(array_filter((array) ($payload['items'] ?? []), 'is_array')), 0, 50) as $raw) {
            $item = self::ratingItem($raw);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return ['available' => true] + self::aggregate($payload) + ['items' => $items] + self::paging($payload, $page);
    }

    /**
     * One page of the module's visible questions with their visible answers.
     *
     * @return array<string, mixed>
     *
     * @throws PlatformHubException any hub failure other than "not found"
     */
    public function questions(string $name, int $page): array
    {
        $payload = $this->read('questions/' . $name, ['page' => $page]);
        if ($payload === null) {
            return ['available' => false, 'items' => []] + self::paging([], $page);
        }
        $items = [];
        foreach (array_slice(array_values(array_filter((array) ($payload['items'] ?? []), 'is_array')), 0, 50) as $raw) {
            $question = self::post($raw);
            if ($question === null) {
                continue;
            }
            $answers = [];
            foreach (array_slice(array_values(array_filter((array) ($raw['answers'] ?? []), 'is_array')), 0, 50) as $answer) {
                $answer = self::post($answer);
                if ($answer !== null) {
                    $answers[] = $answer;
                }
            }
            $items[] = $question + ['answers' => $answers];
        }

        return ['available' => true, 'items' => $items] + self::paging($payload, $page);
    }

    /**
     * Rate the module (or edit this site's rating) as the given Webmaster.
     *
     * @return array{rating: array<string, mixed>|null}
     *
     * @throws PlatformHubException
     */
    public function rate(string $name, User $user, int $stars, ?string $body, ?string $authorName): array
    {
        $payload = $this->author($user, $authorName) + ['stars' => $stars, 'body' => $body];
        $answer = $this->client()->put(self::HUB_PATH . 'ratings/' . $name, $payload);
        $rating = is_array($answer['rating'] ?? null) ? self::ratingItem($answer['rating']) : null;
        if ($rating !== null) {
            $rating['hidden'] = ($answer['rating']['hidden'] ?? false) === true;
        }

        return ['rating' => $rating];
    }

    /**
     * Ask a public question about the module as the given Webmaster.
     *
     * @return array{question: array<string, mixed>|null}
     *
     * @throws PlatformHubException
     */
    public function ask(string $name, User $user, string $body, ?string $authorName): array
    {
        $answer = $this->client()->post(self::HUB_PATH . 'questions/' . $name, $this->author($user, $authorName) + ['body' => $body]);
        $question = is_array($answer['question'] ?? null) ? self::post($answer['question']) : null;

        return ['question' => $question === null ? null : $question + ['answers' => []]];
    }

    /**
     * Send private feedback about the module to its publisher as the given Webmaster.
     *
     * @return array{sent: bool}
     *
     * @throws PlatformHubException
     */
    public function feedback(string $name, User $user, string $kind, string $body, ?string $authorName): array
    {
        $this->client()->post(self::HUB_PATH . 'feedback/' . $name, $this->author($user, $authorName) + ['kind' => $kind, 'body' => $body]);

        return ['sent' => true];
    }

    /**
     * The validated rating aggregate of a hub answer or catalog summary: average (1-5, one decimal) and count.
     *
     * @return array{average: float|null, count: int}
     */
    public static function aggregate(mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['average' => null, 'count' => 0];
        }
        $average = $raw['average'] ?? null;
        $count = $raw['count'] ?? null;
        if (! is_int($count) || $count < 1 || ! (is_int($average) || is_float($average)) || $average < 1 || $average > 5) {
            return ['average' => null, 'count' => 0];
        }

        return ['average' => round((float) $average, 1), 'count' => $count];
    }

    /**
     * The author fields of a write: pseudonymous reference and masked display name.
     *
     * @return array{author_ref: string, author_name: string}
     *
     * @throws PlatformHubException when the site has no domain secret
     */
    private function author(User $user, ?string $authorName): array
    {
        $secret = app(HubCredentials::class)->secret();
        if ($secret === '') {
            throw new PlatformHubException(PlatformHubException::NOT_CONFIGURED, 'Platform hub domain or secret is not configured.');
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $authorName));
        if ($name === '') {
            $name = self::defaultName($user->name);
        }
        $name = trim(mb_substr($name, 0, self::MAX_NAME));

        return [
            'author_ref' => self::authorRef((string) $user->uuid, $secret),
            'author_name' => $name !== '' ? $name : self::FALLBACK_NAME,
        ];
    }

    /**
     * A signed GET; null when the hub answers 404 (module unknown to it, or a hub without community endpoints).
     *
     * @param array<string, mixed> $query
     *
     * @return array<array-key, mixed>|null
     */
    private function read(string $path, array $query = []): ?array
    {
        try {
            return $this->client()->get(self::HUB_PATH . $path, $query);
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * The hub client (built lazily, so a site without hub credentials fails only when it calls the hub).
     */
    private function client(): PlatformHubClient
    {
        return $this->clientFactory !== null ? ($this->clientFactory)() : new PlatformHubClient();
    }

    /**
     * A validated public rating; null when the stars are not 1-5.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{stars: int, body: string|null, version: string|null, author_name: string, updated_at: string|null}|null
     */
    private static function ratingItem(array $raw): ?array
    {
        $stars = $raw['stars'] ?? null;
        if (! is_int($stars) || $stars < 1 || $stars > 5) {
            return null;
        }

        return [
            'stars' => $stars,
            'body' => self::text($raw['body'] ?? null, self::MAX_BODY),
            'version' => self::text($raw['version'] ?? null, 40),
            'author_name' => self::text($raw['author_name'] ?? null, self::MAX_NAME) ?? '',
            'updated_at' => self::text($raw['updated_at'] ?? null, 40),
        ];
    }

    /**
     * A validated question or answer (without its answers); null without a body.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{body: string, author_kind: string, author_name: string, created_at: string|null}|null
     */
    private static function post(array $raw): ?array
    {
        $body = self::text($raw['body'] ?? null, self::MAX_FEEDBACK_BODY);
        if ($body === null) {
            return null;
        }
        $kind = $raw['author_kind'] ?? null;

        return [
            'body' => $body,
            'author_kind' => in_array($kind, self::AUTHOR_KINDS, true) ? $kind : 'site',
            'author_name' => self::text($raw['author_name'] ?? null, self::MAX_NAME) ?? '',
            'created_at' => self::text($raw['created_at'] ?? null, 40),
        ];
    }

    /**
     * Paging fields of a hub page answer.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array{page: int, per_page: int, total: int}
     */
    private static function paging(array $payload, int $page): array
    {
        $int = static fn (mixed $v, int $default): int => is_int($v) && $v >= 0 ? $v : $default;

        return [
            'page' => max(1, $int($payload['page'] ?? null, $page)),
            'per_page' => max(1, $int($payload['per_page'] ?? null, 10)),
            'total' => $int($payload['total'] ?? null, 0),
        ];
    }

    /**
     * A trimmed string within $max characters, or null (empty, not a string, too long).
     */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }
}
