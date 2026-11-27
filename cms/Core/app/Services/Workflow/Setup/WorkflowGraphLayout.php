<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Setup;

/**
 * Canvas positions for a workflow an installer creates, the same as the designer's "Tidy up" (base-admin
 * `src/components/workflows/graphLayout.js`, `layoutGraph`) gives with every card at its unmeasured size, so an
 * installed template opens readable and Tidy up leaves it where it is.
 *
 * The flow reads top to bottom: a link enters a card at the top and leaves from its bottom. A step sits one row below
 * the lowest step that leads to it; the next steps of a branch sit side by side in the order of its outputs (the first
 * output on the left); links that go back to a step already on the way (a loop's "next item") are left out of the rows;
 * what comes after a loop sits below every step the loop repeats, with room for the loop's frame.
 *
 * Only positions come out: steps and links are never changed. Keep it in step with graphLayout.js.
 */
final class WorkflowGraphLayout
{
    public const CARD_WIDTH = 300;

    public const CARD_HEIGHT = 96;

    private const COLUMN_GAP = 48;

    private const ROW_GAP = 64;

    /** Room above the first step of a loop for the frame title, and below its last step. */
    private const FRAME_HEAD = 48;

    private const FRAME_PAD = 24;

    private const ORIGIN_X = 40;

    private const ORIGIN_Y = 32;

    /** Outputs of a loop step that lead into the steps it repeats. */
    private const LOOP_HANDLES = ['each', 'loop', 'batch'];

    private const ERROR_HANDLE = 'error';

    /**
     * @param list<string> $stepCodes the steps in graph order, the start step first (as the installer creates them)
     * @param list<array{0: string, 1: string, 2?: string|null}> $edges links [from, to, output handle], in the order the
     *                                                                   installer creates them
     * @return array<string, array{0: int, 1: int}> step code => [x, y]
     */
    public static function tidy(array $stepCodes, array $edges): array
    {
        $codes = array_values(array_unique($stepCodes));
        if ($codes === []) {
            return [];
        }

        $links = self::cleanLinks($codes, $edges);
        [$order, $back] = self::walk($codes, $links);
        $rank = array_flip($order);
        $forward = array_values(array_filter($links, static fn (array $link): bool => ! isset($back[$link['id']])));
        $bodies = self::loopBodies($codes, $forward);

        // What comes after a loop sits below everything the loop repeats.
        $below = [];
        $firstOfLoop = [];
        $bodyIds = [];
        foreach ($bodies as $loop => $body) {
            $inside = array_flip($body);
            foreach ($body as $code) {
                $bodyIds[$code] = true;
            }
            foreach ($forward as $link) {
                if ($link['source'] !== $loop) {
                    continue;
                }
                if (isset($inside[$link['target']])) {
                    $firstOfLoop[$link['target']] = true;
                } else {
                    foreach ($body as $code) {
                        $below[] = ['source' => $code, 'target' => $link['target']];
                    }
                }
            }
        }

        // Rows: a step sits one row below the lowest step that leads to it.
        $incoming = [];
        $outgoing = [];
        foreach ([...$forward, ...$below] as $link) {
            $incoming[$link['target']] = ($incoming[$link['target']] ?? 0) + 1;
            $outgoing[$link['source']][] = $link['target'];
        }
        $layerOf = [];
        $queue = array_values(array_filter($order, static fn (string $code): bool => empty($incoming[$code])));
        foreach ($queue as $code) {
            $layerOf[$code] = 0;
        }
        for ($i = 0; $i < count($queue); $i++) {
            $code = $queue[$i];
            foreach ($outgoing[$code] ?? [] as $target) {
                $layerOf[$target] = max($layerOf[$target] ?? 0, $layerOf[$code] + 1);
                if (--$incoming[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }
        $deepest = max(0, ...array_values($layerOf));
        $layers = [];
        foreach ($order as $code) {
            $layerOf[$code] ??= $deepest + 1;
            $layers[$layerOf[$code]][] = $code;
        }
        ksort($layers);

        // Columns: a step sits under the steps that lead to it; the outputs of one step spread left to right.
        $children = [];
        $parents = [];
        foreach ($forward as $link) {
            if (! in_array($link['target'], $children[$link['source']] ?? [], true)) {
                $children[$link['source']][] = $link['target'];
            }
            if (! in_array($link['source'], $parents[$link['target']] ?? [], true)) {
                $parents[$link['target']][] = $link['source'];
            }
        }
        $half = self::CARD_WIDTH / 2;
        $spread = self::CARD_WIDTH + self::COLUMN_GAP;
        $center = [];
        $wish = static function (string $code) use (&$center, $parents, $children, $spread): ?float {
            $placed = array_values(array_filter($parents[$code] ?? [], static fn (string $parent): bool => isset($center[$parent])));
            if ($placed === []) {
                return null;
            }
            $total = 0.0;
            foreach ($placed as $parent) {
                $siblings = $children[$parent];
                $total += $center[$parent] + (array_search($code, $siblings, true) - (count($siblings) - 1) / 2) * $spread;
            }

            return $total / count($placed);
        };
        $freeRight = -INF;
        foreach ($layers as $layer) {
            $wished = array_map(static fn (string $code): array => ['code' => $code, 'x' => $wish($code)], $layer);
            usort($wished, static fn (array $a, array $b): int => [$a['x'] === null, $a['x'] ?? 0, $rank[$a['code']]]
                <=> [$b['x'] === null, $b['x'] ?? 0, $rank[$b['code']]]);
            $right = -INF;
            foreach ($wished as ['code' => $code, 'x' => $x]) {
                $edge = max($right, $x === null ? $freeRight : -INF);
                // A step nothing leads to starts its own column, right of what is placed so far.
                $wanted = $x ?? ($edge === -INF ? 0.0 : $edge + self::COLUMN_GAP + $half);
                $center[$code] = $right === -INF ? $wanted : max($wanted, $right + self::COLUMN_GAP + $half);
                $right = $center[$code] + $half;
            }
            $freeRight = max($freeRight, $right);
        }

        // Rows from the top, with room for the frame of a loop.
        $inBody = static fn (array $codes): bool => array_filter($codes, static fn (string $code): bool => isset($bodyIds[$code])) !== [];
        $top = [];
        $y = 0;
        $previous = null;
        foreach ($layers as $index => $layer) {
            if (array_filter($layer, static fn (string $code): bool => isset($firstOfLoop[$code])) !== []) {
                $y += self::FRAME_HEAD;
            } elseif ($previous !== null && $inBody($previous) && ! $inBody($layer)) {
                $y += self::FRAME_PAD;
            }
            $top[$index] = $y;
            $y += self::CARD_HEIGHT + self::ROW_GAP;
            $previous = $layer;
        }

        $left = min(array_map(static fn (string $code): float => $center[$code] - $half, $order));
        $positions = [];
        foreach ($order as $code) {
            $positions[$code] = [
                (int) round($center[$code] - $half - $left + self::ORIGIN_X),
                (int) round($top[$layerOf[$code]] + self::ORIGIN_Y),
            ];
        }

        return $positions;
    }

    /**
     * Links between known steps, without self links; a step's error link after its other links.
     *
     * @param list<string> $codes
     * @param list<array{0: string, 1: string, 2?: string|null}> $edges
     * @return list<array{id: int, source: string, target: string, handle: string|null}>
     */
    private static function cleanLinks(array $codes, array $edges): array
    {
        $known = array_flip($codes);
        $links = [];
        foreach (array_values($edges) as $id => $edge) {
            [$source, $target] = $edge;
            if (isset($known[$source], $known[$target]) && $source !== $target) {
                $links[] = ['id' => $id, 'source' => $source, 'target' => $target, 'handle' => $edge[2] ?? null];
            }
        }
        $isError = static fn (array $link): bool => $link['handle'] === self::ERROR_HANDLE;

        return [
            ...array_values(array_filter($links, static fn (array $link): bool => ! $isError($link))),
            ...array_values(array_filter($links, $isError)),
        ];
    }

    /**
     * Walk from the start and tell forward links from links back to a step already on the way.
     *
     * @param list<string> $codes
     * @param list<array{id: int, source: string, target: string, handle: string|null}> $links
     * @return array{0: list<string>, 1: array<int, true>} steps in the order they are met, ids of the links that go back
     */
    private static function walk(array $codes, array $links): array
    {
        $next = [];
        $hasIncoming = [];
        foreach ($links as $link) {
            $next[$link['source']][] = $link;
            $hasIncoming[$link['target']] = true;
        }
        $roots = [
            $codes[0],
            ...array_filter(array_slice($codes, 1), static fn (string $code): bool => ! isset($hasIncoming[$code])),
            ...$codes,
        ];

        $state = [];
        $order = [];
        $back = [];
        foreach ($roots as $root) {
            if (isset($state[$root])) {
                continue;
            }
            $state[$root] = 'open';
            $order[] = $root;
            $stack = [[$root, 0]];
            while ($stack !== []) {
                $top = count($stack) - 1;
                [$code, $index] = $stack[$top];
                $stack[$top][1]++;
                $link = $next[$code][$index] ?? null;
                if ($link === null) {
                    $state[$code] = 'done';
                    array_pop($stack);
                } elseif (($state[$link['target']] ?? null) === 'open') {
                    $back[$link['id']] = true;
                } elseif (! isset($state[$link['target']])) {
                    $state[$link['target']] = 'open';
                    $order[] = $link['target'];
                    $stack[] = [$link['target'], 0];
                }
            }
        }

        return [$order, $back];
    }

    /**
     * Steps each loop repeats: what its loop output leads to, without the steps its other outputs also reach.
     *
     * @param list<string> $codes
     * @param list<array{id: int, source: string, target: string, handle: string|null}> $forward
     * @return array<string, list<string>> loop step code => repeated step codes
     */
    private static function loopBodies(array $codes, array $forward): array
    {
        $next = [];
        foreach ($forward as $link) {
            $next[$link['source']][] = $link;
        }
        $reach = static function (array $starts, string $blocked) use ($next): array {
            $seen = [];
            $queue = $starts;
            while ($queue !== []) {
                $code = array_shift($queue);
                if ($code === $blocked || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                foreach ($next[$code] ?? [] as $link) {
                    $queue[] = $link['target'];
                }
            }

            return array_keys($seen);
        };
        $isLoop = static fn (array $link): bool => in_array($link['handle'], self::LOOP_HANDLES, true);

        $bodies = [];
        foreach ($codes as $code) {
            $mine = $next[$code] ?? [];
            $into = array_column(array_filter($mine, $isLoop), 'target');
            if ($into === []) {
                continue;
            }
            $after = array_flip($reach(array_column(array_filter($mine, static fn (array $link): bool => ! $isLoop($link)), 'target'), $code));
            $body = array_values(array_filter($reach($into, $code), static fn (string $step): bool => ! isset($after[$step])));
            if ($body !== []) {
                $bodies[$code] = $body;
            }
        }

        return $bodies;
    }
}
