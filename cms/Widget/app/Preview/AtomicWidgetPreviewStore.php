<?php

declare(strict_types=1);

namespace Cms\Widget\Preview;

/**
 * Atomic single-use preview overlay store (plan Chunk 2 Task 19, DoD 1103;
 * docs/architecture/adr-widget-preview-session.md decision 3).
 *
 * `Illuminate\Support\Facades\Cache::pull()` (get-then-forget) is FORBIDDEN
 * for this contract -- it is two Redis round trips, not atomic, and a
 * concurrent second reader can observe the value between them. Every
 * implementation of this interface MUST perform the get-and-delete as ONE
 * atomic server-side operation (Redis `GETDEL`, or a single Lua script).
 *
 * This interface deliberately knows nothing about encryption, TTL policy
 * beyond the caller-supplied seconds, or the shape of the stored payload --
 * it is a pure atomic single-use string store. {@see WidgetPreviewSessionService}
 * owns encrypting/decrypting the JSON envelope stored as the value.
 */
interface AtomicWidgetPreviewStore
{
    /**
     * Store $value under $token with a bounded TTL. Overwrites silently if
     * the token were somehow already present (token collision is a
     * 256-bit-random-space event; this method does not special-case it).
     */
    public function put(string $token, string $value, int $ttlSeconds): void;

    /**
     * Atomically get-and-delete the value stored under $token. Returns null
     * when the token was never minted, already consumed (single-use), or
     * has expired -- all three are indistinguishable at this layer by
     * design (the caller cannot learn WHICH of the three occurred, which is
     * exactly the "fail without revealing target metadata" contract).
     */
    public function consume(string $token): ?string;
}
