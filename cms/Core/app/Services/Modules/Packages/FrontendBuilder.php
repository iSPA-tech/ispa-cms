<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Builds the declared front-end entries of one module into prebuilt, hashed files for its package.
 *
 * Runs `pnpm exec vite build --config vite.module.config.mjs` in base_path() through Symfony Process, with a timeout
 * and a cleaned environment (no `.env` value, no VITE_* or APP_URL from the builder's shell reaches the bundle), into
 * a fresh temp folder that is always removed. Every output file must have an allowed extension (no source maps, HTML
 * or PHP), be a regular file, and hold no absolute path of the builder (module folder, ispa-cms root, temp folder).
 * The output is returned in memory; PackageBuilder adds it under `<Module>/frontend-build/` before hashing, so the
 * signed `files` map covers every built byte. The site never runs this: it only copies the signed files.
 */
final class FrontendBuilder
{
    /** Folder of the package that holds the build, relative to the module. */
    public const BUILD_DIR = 'frontend-build';

    /** The Vite manifest file name inside the build folder. */
    public const MANIFEST = 'manifest.json';

    /** Shared config in the ispa-cms root. */
    public const CONFIG = 'vite.module.config.mjs';

    /** Extensions a built file may have; `json` only for the Vite manifest. */
    public const ALLOWED_EXTENSIONS = ['js', 'css', 'woff2', 'woff', 'svg', 'png', 'webp', 'jpg', 'gif'];

    /** Environment variables passed through to the build; every other inherited variable is removed. */
    private const PASS_ENV = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'TMPDIR', 'TMP', 'TEMP', 'LANG', 'LC_ALL', 'LC_CTYPE',
        'PNPM_HOME', 'COREPACK_HOME', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'SYSTEMROOT', 'SystemRoot',
    ];

    /**
     * @param string|null $pnpm       pnpm executable (default: found on PATH)
     * @param string|null $workingDir folder holding vite.module.config.mjs and node_modules (default base_path())
     * @param int         $timeout    seconds before the build is stopped
     */
    public function __construct(
        private readonly ?string $pnpm = null,
        private readonly ?string $workingDir = null,
        private readonly int $timeout = 300,
    ) {
    }

    /**
     * Build the entries of a module and return the output: relative path => bytes, sorted by path.
     *
     * @param string       $module    module folder name
     * @param string       $moduleDir absolute module folder (the Vite root)
     * @param list<string> $entries   declared entries, relative to the module folder, already linted
     *
     * @return array<string, string>
     *
     * @throws PackageBuildException FRONTEND_BUILD when the tools are missing, the build fails or the output is refused
     */
    public function build(string $module, string $moduleDir, array $entries): array
    {
        $cwd = $this->workingDir ?? base_path();
        $pnpm = $this->pnpm ?? (new ExecutableFinder())->find('pnpm');
        if ($pnpm === null || !is_file($pnpm) || !is_executable($pnpm)) {
            throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'Building the front-end assets of ' . $module . ' needs Node and pnpm on PATH (pnpm was not found).');
        }
        if (!is_file($cwd . '/' . self::CONFIG) || !is_dir($cwd . '/node_modules/vite')) {
            throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'Building the front-end assets of ' . $module . ' needs ' . self::CONFIG . ' and an installed node_modules (pnpm install) in ' . $cwd . '.');
        }

        $tmp = sys_get_temp_dir() . '/pkgbuild-fe-' . bin2hex(random_bytes(6));
        $moduleDir = (string) (realpath($moduleDir) ?: $moduleDir);
        if (!@mkdir($tmp . '/out', 0700, true)) {
            throw new PackageBuildException(PackageBuildException::IO, 'Cannot create a temp folder for the front-end build.');
        }
        try {
            $process = new Process([$pnpm, 'exec', 'vite', 'build', '--config', self::CONFIG], $cwd, $this->environment([
                'ISPA_MODULE_NAME' => $module,
                'ISPA_MODULE_DIR' => $moduleDir,
                'ISPA_MODULE_OUT_DIR' => $tmp . '/out',
                'ISPA_MODULE_CACHE_DIR' => $tmp . '/cache',
                'ISPA_MODULE_ENTRIES' => json_encode(array_values($entries), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'NODE_ENV' => 'production',
                'NO_COLOR' => '1',
                'FORCE_COLOR' => '0',
            ]), null, $this->timeout);
            try {
                $process->run();
            } catch (Throwable $e) {
                throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'The front-end build of ' . $module . ' did not finish: ' . $e->getMessage());
            }
            if (!$process->isSuccessful()) {
                throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'The front-end build of ' . $module . ' failed (exit ' . $process->getExitCode() . '): ' . $this->tail($process->getErrorOutput() . "\n" . $process->getOutput()));
            }

            $out = $this->collect($module, $tmp . '/out', [$moduleDir, (string) realpath($moduleDir), $cwd, (string) realpath($cwd), $tmp, (string) realpath($tmp)]);
            $this->checkViteManifest($module, $out, $entries);

            return $out;
        } finally {
            $this->remove($tmp);
        }
    }

    /**
     * Read the build output: allowed extensions only, regular files only, the Vite manifest required, and no file
     * holding one of the builder's absolute paths.
     *
     * @param array<int, string> $forbidden absolute paths that must not appear in any output file
     *
     * @return array<string, string>
     */
    private function collect(string $module, string $outDir, array $forbidden): array
    {
        $forbidden = array_values(array_unique(array_filter($forbidden, static fn (string $p): bool => strlen($p) > 1)));
        $out = [];
        foreach ($this->walk($outDir) as $relative) {
            $full = $outDir . '/' . $relative;
            if (is_link($full) || !is_file($full)) {
                throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'The front-end build of ' . $module . ' produced something that is not a regular file: ' . $relative);
            }
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if ($relative !== self::MANIFEST && !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'The front-end build of ' . $module . ' produced ' . $relative . ' (.' . $extension . '); allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS) . ' and ' . self::MANIFEST . '.');
            }
            $bytes = (string) file_get_contents($full);
            foreach ($forbidden as $path) {
                if (str_contains($bytes, $path)) {
                    throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'The front-end build of ' . $module . ' wrote an absolute path of the builder into ' . $relative . '.');
                }
            }
            $out[$relative] = $bytes;
        }
        if (!isset($out[self::MANIFEST])) {
            throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'The front-end build of ' . $module . ' wrote no ' . self::MANIFEST . '.');
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Check the Vite manifest against the output: a JSON object keyed by relative source paths (no absolute or ".."
     * key), one key per declared entry, and every `file`, `css` and `assets` path it names present in the output.
     * The site's installer and asset helper rely on this shape.
     *
     * @param array<string, string> $out     output relative path => bytes
     * @param list<string>          $entries declared entries
     */
    private function checkViteManifest(string $module, array $out, array $entries): void
    {
        $fail = static fn (string $why): PackageBuildException => new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'The front-end build of ' . $module . ' wrote an unusable ' . self::MANIFEST . ': ' . $why . '.');
        $manifest = json_decode($out[self::MANIFEST], true);
        if (!is_array($manifest) || $manifest === [] || array_is_list($manifest)) {
            throw $fail('not a JSON object');
        }
        foreach ($manifest as $key => $chunk) {
            $key = (string) $key;
            if ($key === '' || str_starts_with($key, '/') || in_array('..', explode('/', $key), true) || !is_array($chunk)) {
                throw $fail('a key is not a relative source path');
            }
            $named = [$chunk['file'] ?? null, ...(array) ($chunk['css'] ?? []), ...(array) ($chunk['assets'] ?? [])];
            foreach ($named as $file) {
                if (!is_string($file) || !isset($out[$file]) || $file === self::MANIFEST) {
                    throw $fail('a chunk names a file the build did not write');
                }
            }
        }
        foreach ($entries as $entry) {
            if (($manifest[$entry]['isEntry'] ?? false) !== true) {
                throw $fail('the declared entry ' . $entry . ' is missing');
            }
        }
    }

    /**
     * Relative paths of every entry under a folder (folders are descended, not listed), sorted.
     *
     * @return list<string>
     */
    private function walk(string $root, string $prefix = ''): array
    {
        $names = array_values(array_diff(scandir($root . ($prefix === '' ? '' : '/' . $prefix)) ?: [], ['.', '..']));
        sort($names, SORT_STRING);
        $out = [];
        foreach ($names as $name) {
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            $full = $root . '/' . $relative;
            if (is_dir($full) && !is_link($full)) {
                array_push($out, ...$this->walk($root, $relative));
                continue;
            }
            $out[] = $relative;
        }

        return $out;
    }

    /**
     * The process environment: every inherited variable removed (false) except PASS_ENV, plus the given values.
     *
     * @param array<string, string> $set
     *
     * @return array<string, string|false>
     */
    private function environment(array $set): array
    {
        $inherited = array_merge(
            array_filter($_SERVER, static fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH),
            $_ENV,
            getenv(),
        );
        $env = [];
        foreach (array_keys($inherited) as $name) {
            $name = (string) $name;
            if ($name !== '' && !in_array($name, self::PASS_ENV, true)) {
                $env[$name] = false;
            }
        }

        return array_merge($env, $set);
    }

    /**
     * Last non-empty lines of the build output (at most 2000 characters) for an error message.
     */
    private function tail(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), static fn (string $l): bool => $l !== ''));
        $text = implode(' | ', array_slice($lines, -12));

        return mb_substr($text, 0, 2000);
    }

    /**
     * Remove a temp tree (symlinks are unlinked, never followed).
     */
    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
