<?php

namespace App\Domain\Replays\Lua;

/**
 * Beyond All Reason's unit list, `unitdefs:` and zlib compressed JSON of names.
 * It is read only when Coilbox would read it (#701), as `bar_unit_def_names`
 * does, so a summary is never checked against a list Coilbox ignored.
 */
final readonly class BarUnitList implements LuaDecoder
{
    private const MAX_NAMES = 65_536;

    private const MAX_NAME_BYTES = 256;

    /** Coilbox reads no further than the most names, each with two quotes and a comma. */
    private const MAX_JSON_BYTES = self::MAX_NAMES * (self::MAX_NAME_BYTES + 3);

    private const SPACE = " \t\n\r";

    /** The next string in the array and the comma or bracket after it. */
    private const ENTRY = '/\G[ \t\n\r]*+("(?>[^"\\\\]++|\\\\.)*+")[ \t\n\r]*+([,\]])/';

    public function kind(): string
    {
        return 'unit_list';
    }

    public function prefix(): string
    {
        return 'unitdefs:';
    }

    public function decode(string $data): ?object
    {
        $json = $this->inflate(substr($data, strlen($this->prefix())));
        $names = $json === null ? null : $this->names($json);

        return $names === null ? null : new UnitList($names);
    }

    /**
     * The first `MAX_JSON_BYTES` of the data, inflated a piece at a time so a
     * longer stream is never held whole. Null when it is not zlib, or ends
     * early without reaching the limit.
     */
    private function inflate(string $compressed): ?string
    {
        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
        $out = '';
        foreach (str_split($compressed, 1024) as $piece) {
            $part = @inflate_add($inflate, $piece);
            if ($part === false) {
                return null;
            }
            $out .= $part;
            if (strlen($out) >= self::MAX_JSON_BYTES) {
                return substr($out, 0, self::MAX_JSON_BYTES);
            }
            if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                return $out;
            }
        }

        return null;
    }

    /**
     * The names of a JSON array of 1 to `MAX_NAMES` usable names. Read one at
     * a time, because decoding the whole of some other JSON of this size takes
     * more memory than a queue job has.
     *
     * @return list<string>|null
     */
    private function names(string $json): ?array
    {
        $at = strspn($json, self::SPACE);
        if (($json[$at] ?? '') !== '[') {
            return null;
        }
        $at++;
        $names = [];
        do {
            if (count($names) === self::MAX_NAMES || ! preg_match(self::ENTRY, $json, $entry, 0, $at)) {
                return null;
            }
            $name = json_decode($entry[1]);
            // Cc is what Rust's `char::is_control` matches.
            if (! is_string($name) || $name === '' || strlen($name) > self::MAX_NAME_BYTES || preg_match('/\p{Cc}/u', $name)) {
                return null;
            }
            $names[] = $name;
            $at += strlen($entry[0]);
        } while ($entry[2] === ',');

        return $at + strspn($json, self::SPACE, $at) === strlen($json) ? $names : null;
    }
}
