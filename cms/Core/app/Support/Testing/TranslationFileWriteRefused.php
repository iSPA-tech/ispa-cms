<?php

namespace Cms\Core\Support\Testing;

use RuntimeException;

/**
 * Raised when code running under the test suite tries to write a protected translation file.
 *
 * Translation files are the i18n source of truth; a damaged file is restored from git and
 * never regenerated, so tests must not be able to rewrite them.
 */
class TranslationFileWriteRefused extends RuntimeException
{
    /**
     * Build the refusal for a protected path.
     *
     * @param string $path Absolute or relative path the caller tried to write.
     *
     * @return self
     */
    public static function forPath(string $path): self
    {
        return new self(
            'Refusing to write protected translation file while tests run: ' . $path
            . '. Tests must write to a temp target (storage/framework/testing or sys_get_temp_dir()); '
            . 'if the file was damaged, restore it with `git checkout -- <file>`, never regenerate it.'
        );
    }
}
