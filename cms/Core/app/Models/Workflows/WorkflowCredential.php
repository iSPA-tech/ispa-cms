<?php

declare(strict_types=1);

namespace Cms\Core\Models\Workflows;

use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A named credential a workflow node uses through `$bind:<name>` (Telegram bot, SMTP server, Facebook page, HTTP
 * auth, text-to-speech key). The payload is encrypted at rest and never leaves the server through the admin API.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $name
 * @property string      $type
 * @property string|null $description
 * @property array       $payload
 *
 * @package Cms\Core\Models\Workflows
 * @since   2026-11-24
 */
class WorkflowCredential extends Model
{
    use HasUuid;

    public const TABLE = 'workflow_credentials';

    /**
     * Payload fields per type: field => required. Every field counts as secret for redaction except the ones in
     * PLAIN_FIELDS (short or public values that would redact ordinary text).
     */
    public const FIELDS = [
        'telegram_bot' => ['token' => true],
        'smtp' => [
            'host' => true, 'port' => true, 'username' => false, 'password' => false, 'encryption' => false,
            'from_address' => true, 'from_name' => false,
        ],
        'facebook_page' => ['page_id' => true, 'access_token' => true],
        // allowed_hosts: where http_request may send the credential (comma-separated host entries, same forms as the
        // site's allowed hosts list). Optional so an http_header credential can serve as an HMAC key only; http_request
        // refuses a credential without it.
        'http_header' => ['header' => true, 'value' => true, 'allowed_hosts' => false],
        'http_basic' => ['username' => true, 'password' => true, 'allowed_hosts' => false],
        'tts' => ['api_key' => true],
    ];

    /** Fields whose values are not secret (never used as redaction needles). */
    public const PLAIN_FIELDS = ['host', 'port', 'encryption', 'from_address', 'from_name', 'header', 'page_id', 'username', 'allowed_hosts'];

    /** @var string */
    protected $table = self::TABLE;

    /** @var array<int, string> */
    protected $fillable = ['name', 'type', 'description', 'payload', 'created_by', 'updated_by', 'last_used_at', 'last_tested_at', 'last_test_ok'];

    /** @var array<int, string> */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'last_used_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * Which payload fields hold a value (booleans only, never the values).
     *
     * @return array<string, bool>
     */
    public function fieldsSet(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $set = [];
        foreach (array_keys(self::FIELDS[$this->type] ?? []) as $field) {
            $value = $payload[$field] ?? null;
            $set[$field] = $value !== null && $value !== '';
        }

        return $set;
    }
}
