<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Artifacts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Production byte-storage primitive for immutable, project-scoped,
 * content-addressed Widget artifact Blobs (plan Chunk 2 Task 13b;
 * docs/architecture/adr-widget-artifact-store.md decision 10; Task 5a
 * topology evidence, docs/evidence/widget-site-layout-preflight.md
 * "4. Artifact topology").
 *
 * Task 5a proved production today is a SINGLE application node with one
 * local POSIX disk trivially shared across every process of that
 * deployment, with atomic same-filesystem `rename()` and consistent
 * read-after-write -- this class IS that approved topology's adapter. It
 * MUST NOT be reused for per-node local disk on MULTIPLE public nodes
 * without a fresh Task-5a-grade evidence pass
 * ({@see WidgetArtifactBlobRepository}'s own docblock repeats this
 * boundary).
 *
 * Write algorithm (artifact-store ADR decision 10's publication flow,
 * verbatim): write bytes to a same-directory temporary file, verify the
 * temp file's own checksum, then atomic `rename()` into the final
 * content-addressed path. The final path is only ever the rename() target
 * -- this class never opens the final path for writing directly, and the
 * checksum gate ties the published bytes to the very hash the path is
 * derived from, so a rename over an existing destination can only ever
 * replace it with byte-identical content (benign; see
 * {@see self::write()}'s docblock). The reuse-vs-integrity-failure
 * decision for DIVERGENT stored content belongs to the caller
 * ({@see DatabaseWidgetArtifactBlobRepository}), made via
 * {@see self::read()}/{@see self::existingBytesMatch()} BEFORE calling
 * write().
 *
 * Storage key shape: `{project_name}/{hash-prefix}/{artifact_hash}` (a
 * standard two-character content-addressable-storage fan-out prefix,
 * bounding directory fan-out). Storage root is injected (production wiring
 * roots it at `storage_path('app/widget_artifacts')` --
 * {@see \Cms\Widget\Providers\WidgetServiceProvider::boot()} -- outside the
 * public webroot, matching the artifact-store ADR's "Executable generated
 * artifacts stay outside the public web root with least-privilege
 * ownership.").
 */
final class FilesystemWidgetArtifactBlobStore
{
    private const DISK_NAME = 'widget_artifacts_local';

    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    /**
     * Deterministic project-scoped, content-addressed storage key. Pure
     * function of (projectName, artifactHash) -- never depends on call
     * order or existing state, so two callers computing the key for the
     * same identity always agree.
     *
     * Path-scope guard: `$projectName` becomes a literal path segment under
     * the storage root, so it is restricted to a strict character allowlist
     * (leading alphanumeric, then alphanumeric/underscore/hyphen -- the
     * shape of every real `config('ispa-cms.folder_name')` value, e.g.
     * "ExampleProject", "Default"). Anything else -- separators, dots, empty --
     * throws rather than being sanitized, so a hostile or corrupted project
     * name can never traverse outside its project directory.
     */
    public function storageKeyFor(string $projectName, string $artifactHash): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $projectName)) {
            throw new InvalidArgumentException(sprintf(
                "Refusing to derive a Widget artifact storage key for project name '%s': project names are restricted to [A-Za-z0-9][A-Za-z0-9_-]* because they become literal path segments.",
                $projectName
            ));
        }

        $prefix = substr($artifactHash, 0, 2) !== '' ? substr($artifactHash, 0, 2) : '00';

        return $projectName . '/' . $prefix . '/' . $artifactHash;
    }

    public function diskName(): string
    {
        return self::DISK_NAME;
    }

    /**
     * True when a file already exists at this storage key.
     */
    public function exists(string $storageKey): bool
    {
        return is_file($this->absolutePath($storageKey));
    }

    /**
     * Read back the bytes stored at an existing storage key, or null when
     * nothing is stored there.
     */
    public function read(string $storageKey): ?string
    {
        $path = $this->absolutePath($storageKey);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Existing-byte verification (plan Task 13b checklist: "existing-byte
     * verification"): true when the storage key is either genuinely absent
     * (nothing to compare -- caller should write) or its stored bytes are
     * byte-identical to $bytes. False means a real, on-disk divergence --
     * the caller must treat this as a hard integrity failure and MUST NOT
     * call {@see self::write()} in that case (never overwrite).
     */
    public function existingBytesMatch(string $storageKey, string $bytes): bool
    {
        $stored = $this->read($storageKey);

        return $stored === null || $stored === $bytes;
    }

    /**
     * Temp-write-then-verify-then-atomic-rename publication (artifact-store
     * ADR decision 10). Caller MUST have already established (via
     * {@see self::exists()} / {@see self::read()} /
     * {@see self::existingBytesMatch()}) that no DIVERGENT content is
     * already stored at this key -- the reuse-vs-integrity-failure decision
     * is the caller's, made BEFORE calling write(). This method performs NO
     * destination-existence check of its own: if the destination appears
     * (or already exists) when the rename lands, rename() atomically
     * REPLACES it -- which is benign, because the checksum gate below
     * guarantees the bytes being published match the artifactHash the
     * destination path itself is derived from, so any two writers to the
     * same content-addressed path necessarily carry byte-identical content
     * (real SHA-256 collisions aside, out of scope per the repository
     * contract). This same-key replace-with-identical property is also what
     * makes a same-hash concurrent racer safe without locking.
     *
     * @return array{storage_disk: string, storage_key: string, checksum: string}
     */
    public function write(string $projectName, string $artifactHash, string $bytes): array
    {
        $storageKey = $this->storageKeyFor($projectName, $artifactHash);
        $finalPath = $this->absolutePath($storageKey);
        $directory = dirname($finalPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create Widget artifact storage directory [{$directory}].");
        }

        // Same-directory temp file: guarantees the same filesystem/mount as
        // the final path, which is what makes the rename() below atomic.
        $tempPath = $finalPath . '.tmp-' . bin2hex(random_bytes(8));

        // fopen 'xb' either creates the temp file or fails without creating
        // anything, so this is the last statement allowed OUTSIDE the
        // cleanup-guarded region below: from here on, any throw must pass
        // through the catch that unlinks the temp file.
        $handle = @fopen($tempPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open a temporary Widget artifact file at [{$tempPath}].");
        }

        try {
            try {
                if (fwrite($handle, $bytes) === false) {
                    throw new RuntimeException("Unable to write Widget artifact bytes to [{$tempPath}].");
                }
            } finally {
                fclose($handle);
            }

            // Verify the temp file's own checksum before it is ever visible
            // under the final path -- defense in depth on top of the
            // caller's own hash_equals() check (WidgetArtifactBlobRepository
            // contract, step 1 of the publication flow).
            $writtenChecksum = hash_file('sha256', $tempPath);
            if ($writtenChecksum === false || !hash_equals($writtenChecksum, $artifactHash)) {
                throw WidgetArtifactIntegrityException::checksumMismatch($projectName, $artifactHash);
            }

            chmod($tempPath, 0644);

            // This class never opens $finalPath for writing -- rename() is
            // the ONLY way bytes ever land there. See the method docblock
            // for why a rename over an existing destination is benign
            // (byte-identical by checksum construction); true multi-process
            // race proof is Task 16+/24b's owner, not this primitive.
            if (!rename($tempPath, $finalPath)) {
                throw new RuntimeException("Unable to atomically publish Widget artifact to [{$finalPath}].");
            }
        } catch (\Throwable $exception) {
            // Failed-staging cleanup: never leave an orphaned temp file
            // behind on ANY failure path above -- including an fwrite()
            // failure, which is why the write block itself lives inside
            // this guarded region rather than before it.
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            throw $exception;
        }

        return [
            'storage_disk' => self::DISK_NAME,
            'storage_key' => $storageKey,
            'checksum' => $artifactHash,
        ];
    }

    private function absolutePath(string $storageKey): string
    {
        return rtrim($this->rootPath, '/') . '/' . ltrim($storageKey, '/');
    }
}
