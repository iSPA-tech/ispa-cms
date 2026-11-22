<?php

declare(strict_types=1);

namespace Cms\Content\Services;

/**
 * Shared MathML presentation allowlist for public HTML sanitizers.
 *
 * Formulas written in the admin editor are stored as `<math>` markup inside a
 * text block. Every public sanitizer that re-serializes that HTML uses these
 * lists so a formula survives without opening a script or URL path: no event
 * handlers, no `href`, no `style`, no `xlink:*`, no `src`.
 */
final class MathMlAllowlist
{
    /** @var list<string> MathML presentation elements that may be kept. */
    public const ELEMENTS = [
        'math', 'semantics', 'mrow', 'mi', 'mn', 'mo', 'ms', 'mtext', 'mspace',
        'msub', 'msup', 'msubsup', 'munder', 'mover', 'munderover',
        'mmultiscripts', 'mprescripts', 'none', 'mfrac', 'msqrt', 'mroot',
        'mstyle', 'mpadded', 'mphantom', 'menclose', 'merror',
        'mtable', 'mtr', 'mtd',
    ];

    /** @var list<string> Elements removed together with their content. */
    public const DROPPED_ELEMENTS = ['annotation', 'annotation-xml'];

    /** @var list<string> Lowercase attributes allowed on MathML elements. */
    public const ATTRIBUTES = [
        'display', 'displaystyle', 'scriptlevel', 'mathvariant', 'mathsize',
        'lspace', 'rspace', 'stretchy', 'symmetric', 'maxsize', 'minsize',
        'largeop', 'movablelimits', 'fence', 'separator', 'form', 'accent',
        'accentunder', 'linethickness', 'width', 'height', 'depth', 'voffset',
        'columnalign', 'rowalign', 'rowspacing', 'columnspacing', 'columnlines',
        'rowlines', 'frame', 'notation', 'columnspan', 'rowspan', 'class',
    ];

    public static function isElement(string $tag): bool
    {
        return in_array($tag, self::ELEMENTS, true);
    }
}
