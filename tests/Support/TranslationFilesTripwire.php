<?php

namespace Tests\Support;

use Cms\Core\Support\Testing\TranslationFileGuard;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit/Pest extension that detects test runs which modified tracked translation files.
 *
 * Translation files are the i18n source of truth: a damaged file is restored from git and
 * never regenerated. The tripwire hashes every tracked protected file when the run starts and
 * again when it ends. It only DETECTS: it never rewrites or restores a file. On a change it
 * prints the files to STDERR and forces a non-zero exit status.
 *
 * Failure mechanism: PHPUnit 12 gives extensions no API to alter the result, so the extension
 * registers a shutdown function that calls exit(1) after the runner has finished; a later
 * exit() in a shutdown function replaces the earlier exit status.
 */
final class TranslationFilesTripwire implements Extension
{
    /**
     * Hashes of the protected files taken when the run started, or null when git was unavailable.
     *
     * @var array<string, string>|null
     */
    private static ?array $before = null;

    /**
     * Subscribe to the run start and finish events.
     *
     * @param Configuration       $configuration PHPUnit configuration (unused).
     * @param Facade              $facade        Extension facade used to register subscribers.
     * @param ParameterCollection $parameters    Extension parameters (unused).
     *
     * @return void
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $root = dirname(__DIR__, 2);

        $facade->registerSubscriber(new class ($root) implements ExecutionStartedSubscriber {
            /**
             * @param string $root Project root.
             */
            public function __construct(private readonly string $root)
            {
            }

            /**
             * Take the baseline snapshot.
             *
             * @param ExecutionStarted $event Run start event.
             *
             * @return void
             */
            public function notify(ExecutionStarted $event): void
            {
                TranslationFilesTripwire::start($this->root);
            }
        });

        $facade->registerSubscriber(new class ($root) implements ExecutionFinishedSubscriber {
            /**
             * @param string $root Project root.
             */
            public function __construct(private readonly string $root)
            {
            }

            /**
             * Compare against the baseline and fail the run on changes.
             *
             * @param ExecutionFinished $event Run finish event.
             *
             * @return void
             */
            public function notify(ExecutionFinished $event): void
            {
                TranslationFilesTripwire::finish($this->root);
            }
        });
    }

    /**
     * Record the baseline snapshot for the run.
     *
     * @param string $root Project root (a git work tree).
     *
     * @return void
     */
    public static function start(string $root): void
    {
        self::$before = self::snapshot($root);
    }

    /**
     * Compare the current state with the baseline and fail the process when a file changed.
     *
     * @param string $root Project root (a git work tree).
     *
     * @return void
     */
    public static function finish(string $root): void
    {
        if (self::$before === null) {
            return;
        }

        $changed = self::changedFiles(self::$before, self::snapshot($root));

        if ($changed === []) {
            return;
        }

        fwrite(STDERR, self::message($changed));
        register_shutdown_function(static function (): void {
            exit(1);
        });
    }

    /**
     * Hash every tracked protected translation file below a git work tree.
     *
     * @param string $root Directory inside a git work tree.
     *
     * @return array<string, string>|null Relative path => hash, or null when git is unavailable.
     */
    public static function snapshot(string $root): ?array
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $process = @proc_open(
            ['git', '-C', $root, 'ls-files', '-z'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || $output === false) {
            return null;
        }

        $hashes = [];
        foreach (explode("\0", $output) as $relative) {
            if ($relative === '' || ! TranslationFileGuard::isProtected($relative)) {
                continue;
            }

            $file = rtrim($root, '/') . '/' . $relative;
            $hashes[$relative] = is_file($file) ? (string) md5_file($file) : 'missing';
        }

        return $hashes;
    }

    /**
     * List files whose hash differs between two snapshots.
     *
     * @param array<string, string>      $before Baseline snapshot.
     * @param array<string, string>|null $after  Current snapshot.
     *
     * @return array<int, string>
     */
    public static function changedFiles(array $before, ?array $after): array
    {
        if ($after === null) {
            return [];
        }

        $changed = [];
        foreach ($before as $path => $hash) {
            if (($after[$path] ?? 'missing') !== $hash) {
                $changed[] = $path;
            }
        }

        sort($changed);

        return $changed;
    }

    /**
     * Build the loud STDERR message for a list of changed files.
     *
     * @param array<int, string> $changed Changed relative paths.
     *
     * @return string
     */
    public static function message(array $changed): string
    {
        $lines = [
            '',
            '!!! TRANSLATION FILES WERE MODIFIED BY THE TEST RUN !!!',
            'Translation files are the i18n source of truth and tests must never touch them.',
        ];

        foreach ($changed as $path) {
            $lines[] = '  - ' . $path;
        }

        $lines[] = 'Restore with: git checkout -- <file>   (never regenerate them)';
        $lines[] = 'Then find the test or command that wrote them and point it at a temp target.';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
