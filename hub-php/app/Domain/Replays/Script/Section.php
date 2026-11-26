<?php

namespace App\Domain\Replays\Script;

/**
 * One `[name] { key=value; }` block of a start script. Section names and keys
 * are lower case, the way the engine reads them. Values are kept as written.
 */
final class Section
{
    /** @var array<string, string> */
    public array $keys = [];

    /** @var array<string, Section> */
    public array $children = [];

    public function get(string $key): ?string
    {
        return $this->keys[$key] ?? null;
    }

    public function int(string $key): ?int
    {
        $v = $this->get($key);

        return $v !== null && preg_match('/\A\s*-?\d+\s*\z/', $v) === 1 ? (int) $v : null;
    }

    public function float(string $key): ?float
    {
        $v = $this->get($key);

        return $v !== null && is_numeric(trim($v)) ? (float) $v : null;
    }

    /**
     * The children named `prefix` and a number, such as `player3`, by number.
     *
     * @return array<int, Section>
     */
    public function numbered(string $prefix): array
    {
        $out = [];
        foreach ($this->children as $name => $child) {
            if (preg_match('/\A'.$prefix.'(\d+)\z/', $name, $m) === 1) {
                $out[(int) $m[1]] = $child;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Parses start script text. The parser never fails: text it cannot make
     * sense of is stepped over, as the engine's own parser does.
     */
    public static function parse(string $text): self
    {
        $i = 0;

        return self::body($text, $i, strlen($text));
    }

    private static function body(string $s, int &$i, int $n): self
    {
        $section = new self;
        while (true) {
            self::skipSpace($s, $i, $n);
            if ($i >= $n) {
                return $section;
            }
            $c = $s[$i];
            if ($c === '}') {
                $i++;

                return $section;
            }
            if ($c === '[') {
                $i++;
                $name = strtolower(trim(self::until($s, $i, $n, ']')));
                if ($i < $n) {
                    $i++;
                }
                self::skipSpace($s, $i, $n);
                if ($i < $n && $s[$i] === '{') {
                    $i++;
                    $section->children[$name] = self::body($s, $i, $n);
                }

                continue;
            }
            $key = strtolower(trim(self::until($s, $i, $n, "=;\n{}")));
            if ($i < $n && $s[$i] === '=') {
                $i++;
                $value = trim(self::until($s, $i, $n, ";\n}"));
                if ($i < $n && $s[$i] === ';') {
                    $i++;
                }
                if ($key !== '') {
                    $section->keys[$key] = $value;
                }
            } elseif ($i < $n && $s[$i] !== '}') {
                $i++;
            }
        }
    }

    private static function skipSpace(string $s, int &$i, int $n): void
    {
        while ($i < $n) {
            $i += strspn($s, " \t\r\n\v\f", $i);
            if ($i + 1 < $n && $s[$i] === '/' && $s[$i + 1] === '/') {
                $end = strpos($s, "\n", $i);
                $i = $end === false ? $n : $end;

                continue;
            }

            return;
        }
    }

    private static function until(string $s, int &$i, int $n, string $stops): string
    {
        $len = strcspn($s, $stops, $i);
        $out = substr($s, $i, $len);
        $i += $len;

        return $out;
    }
}
