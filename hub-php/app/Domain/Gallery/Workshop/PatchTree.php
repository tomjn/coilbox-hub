<?php

namespace App\Domain\Gallery\Workshop;

/**
 * A sparse patch, as a tree rather than as a JSON value, ported from
 * `PatchTree` in `lib/workshop/lua.ts`.
 *
 * JSON cannot say "the fourth element of this array and nothing else". A patch
 * against `weapons.3.name` has to come out as `weapons = { [4] = { name = ... } }`
 * with no mention of the three weapons it does not touch, or the merge that
 * applies it would blank them.
 *
 * A node is `['leaf' => value]` or `['branch' => PatchTree]`.
 */
final class PatchTree
{
    /** @var array<int, array> */
    private array $indices = [];

    /** @var array<string, array> */
    private array $names = [];

    public function isEmpty(): bool
    {
        return $this->indices === [] && $this->names === [];
    }

    /** A step of nothing but digits is an array index counted from zero, written one higher because Lua counts from one. */
    private function index(string $step): ?int
    {
        if (preg_match('/^[0-9]+$/D', $step) === 1 && (float) $step <= 9007199254740991.0) {
            return (int) $step + 1;
        }

        return null;
    }

    private function get(string $step): ?array
    {
        $index = $this->index($step);

        return $index !== null ? ($this->indices[$index] ?? null) : ($this->names[$step] ?? null);
    }

    private function set(string $step, array $node): void
    {
        $index = $this->index($step);
        if ($index !== null) {
            $this->indices[$index] = $node;
        } else {
            $this->names[$step] = $node;
        }
    }

    /** Write one dotted path into the tree. A path through a value already set replaces it. */
    public function insert(string $path, mixed $value): void
    {
        $steps = explode('.', $path);
        $step = array_shift($steps);
        if ($steps === []) {
            $this->set($step, ['leaf' => $value]);

            return;
        }
        $entry = $this->get($step);
        if ($entry === null || ! isset($entry['branch'])) {
            $entry = ['branch' => new self];
            $this->set($step, $entry);
        }
        $entry['branch']->insert(implode('.', $steps), $value);
    }

    /** Indices first and in numeric order, then names in key order. */
    public function toLua(string $indent): string
    {
        $inner = $indent.Lua::INDENT;
        $entries = [];
        $indices = $this->indices;
        ksort($indices);
        foreach ($indices as $n => $node) {
            $entries[] = ['['.$n.']', $node];
        }
        foreach (Js::sorted($this->names) as [$name, $node]) {
            $entries[] = [Lua::tableKey($name), $node];
        }

        $allScalars = true;
        $parts = [];
        foreach ($entries as [$key, $node]) {
            if (array_key_exists('leaf', $node)) {
                $parts[] = $key.' = '.Lua::literal($node['leaf'], $inner);
                if (Lua::isTable($node['leaf'])) {
                    $allScalars = false;
                }
            } else {
                $parts[] = $key.' = '.$node['branch']->toLua($inner);
                $allScalars = false;
            }
        }

        return Lua::renderTable($parts, $allScalars, $indent);
    }
}
