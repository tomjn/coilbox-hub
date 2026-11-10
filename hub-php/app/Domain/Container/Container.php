<?php

namespace App\Domain\Container;

use stdClass;

/**
 * The server side of the Coilbox container format, ported from the Next app's
 * `lib/container`, which is vendored from `src/container/container.ts` in the
 * Coilbox repository. Only what the hub needs to accept a share code: decode,
 * recognise, identify and the module geometry rule.
 *
 * The hub must read exactly what the app writes. Change the format in Coilbox
 * first, then follow it here.
 *
 * Decoded JSON is kept as `stdClass` objects and PHP lists, not associative
 * arrays, so `{}` and `[]` stay apart and a container is stored exactly as it
 * arrived. {@see Json} encodes it the way `JSON.stringify` does.
 */
final class Container
{
    public const FORMAT = 'coilbox';

    public const VERSION = 1;

    public const KINDS = [
        'campaign',
        'preset',
        'challenge',
        'setup-pack',
        'scenario',
        'keymap',
        'blueprint',
        'mod-project',
        'module',
    ];

    /** Highest payload schema version this build understands, per kind. */
    public const SUPPORTED_KIND_VERSIONS = [
        'campaign' => 2,
        'preset' => 1,
        'challenge' => 1,
        'setup-pack' => 1,
        'scenario' => 2,
        'keymap' => 1,
        'blueprint' => 1,
        'mod-project' => 1,
        'module' => 1,
    ];

    /**
     * Kinds the gallery carries, out of the nine Coilbox understands. Campaigns
     * inline media past the import ceiling, and keymaps came later. The list is
     * repeated in SQL as a check on `item.kind`.
     */
    public const GALLERY_KINDS = [
        'preset',
        'challenge',
        'setup-pack',
        'scenario',
        'blueprint',
        'mod-project',
        'module',
    ];

    /** Marks a code whose JSON is raw DEFLATE compressed (Coilbox #557). */
    public const COMPRESSED_CODE_PREFIX = 'cbz1.';

    /** Largest JSON inflated from a code, and the largest container the app imports. */
    public const MAX_INFLATED_BYTES = 512 * 1024;

    public const MAX_CONTAINER_BYTES = 512 * 1024;

    /** The five stores a tweak project's `edits` table can hold. */
    private const MOD_PROJECT_SLOTS = ['overrides', 'clones', 'menus', 'text', 'disabled'];

    public static function base64UrlToBytes(string $code): ?string
    {
        $padded = strtr($code, '-_', '+/');
        $rest = strlen($padded) % 4;
        if ($rest !== 0) {
            $padded .= str_repeat('=', 4 - $rest);
        }
        $bytes = base64_decode($padded, true);

        return $bytes === false ? null : $bytes;
    }

    public static function bytesToBase64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Build a canonical container around a payload. */
    public static function make(string $kind, int|float $kindVersion, mixed $payload): stdClass
    {
        return (object) [
            'format' => self::FORMAT,
            'container' => self::VERSION,
            'kind' => $kind,
            'kindVersion' => $kindVersion,
            'payload' => $payload,
        ];
    }

    /** A pasteable code, compressed then base64url behind the prefix. */
    public static function encodeCode(string $kind, int $kindVersion, mixed $payload): string
    {
        $json = Json::encode(self::make($kind, $kindVersion, $payload));

        return self::COMPRESSED_CODE_PREFIX.self::bytesToBase64Url((string) gzdeflate($json, 9));
    }

    /**
     * Decode raw JSON, a compressed code or a plain base64url code, or return
     * null when none parse. Raw JSON is tried first, then a code.
     */
    public static function decodeText(string $text): mixed
    {
        $trimmed = self::trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, self::COMPRESSED_CODE_PREFIX)) {
            $json = self::inflateCode($trimmed);

            return $json === null ? null : Json::decode($json);
        }

        $value = Json::decode($trimmed);
        if ($value !== null) {
            return $value;
        }
        $bytes = self::base64UrlToBytes($trimmed);

        return $bytes === null ? null : Json::decode($bytes);
    }

    /** A parsed value recognised as a canonical container, or null. */
    public static function asContainer(mixed $value): ?stdClass
    {
        if (! $value instanceof stdClass) {
            return null;
        }
        if (($value->format ?? null) !== self::FORMAT) {
            return null;
        }
        if (! self::isNumber($value->container ?? null)) {
            return null;
        }
        if (! is_string($value->kind ?? null)) {
            return null;
        }
        if (! self::isNumber($value->kindVersion ?? null)) {
            return null;
        }
        if (! property_exists($value, 'payload')) {
            return null;
        }

        return $value;
    }

    /** Guess a kind from a payload's shape alone, or null. */
    public static function sniffPayloadKind(mixed $payload): ?string
    {
        if (! self::isObject($payload)) {
            return null;
        }
        $p = self::props($payload);
        $get = fn (string $key) => $p[$key] ?? null;

        if ($get('type') === 'ta' && is_array($get('missions'))) {
            return 'campaign';
        }
        if (self::isObject($get('campaign'))) {
            $c = self::props($get('campaign'));
            if (($c['type'] ?? null) === 'ta' && is_array($c['missions'] ?? null)) {
                return 'campaign';
            }
        }
        if (self::isObject($get('scenario'))) {
            $s = self::props($get('scenario'));
            if (is_array($s['triggers'] ?? null) && is_array($s['zones'] ?? null)) {
                return 'scenario';
            }
        }
        if ((! array_key_exists('engineVersion', $p) || is_string($p['engineVersion']))
            && is_array($get('maps'))
            && self::isObject($get('game'))) {
            return 'setup-pack';
        }
        if (in_array($get('mode'), ['conquest', 'warpath'], true) && self::isObject($get('settings'))) {
            return 'challenge';
        }
        if (is_array($get('bindings'))
            && is_array($get('keysyms'))
            && (array_key_exists('fakeMeta', $p) && ($p['fakeMeta'] === null || is_string($p['fakeMeta'])))) {
            return 'keymap';
        }
        if (is_string($get('name')) && self::isObject($get('edits'))) {
            $edits = self::props($get('edits'));
            foreach (self::MOD_PROJECT_SLOTS as $slot) {
                if (array_key_exists($slot, $edits)) {
                    return 'mod-project';
                }
            }
        }
        if (is_string($get('packId')) && is_string($get('rootPieceId')) && is_array($get('pieces'))) {
            return 'module';
        }
        if (is_string($get('name')) && is_array($get('buildings'))) {
            return 'blueprint';
        }
        if (is_array($get('participants')) && is_string($get('gameName')) && is_string($get('mapName'))) {
            return 'preset';
        }

        return null;
    }

    /**
     * Why a module payload cannot go on the hub, or null when it can (#3272,
     * #4360). Lifted geometry never goes up. A mesh needs a record of the
     * maker's own file, and then the payload has to carry the meshes.
     */
    public static function moduleGeometryRefusal(mixed $payload): ?string
    {
        $doc = self::isObject($payload) ? self::props($payload) : null;
        $pieces = $doc['pieces'] ?? null;
        if (! is_array($pieces)) {
            return 'That module could not be read.';
        }

        $liftedNames = [];
        $games = [];
        $meshNames = [];
        $ownNames = [];
        foreach ($pieces as $piece) {
            if (! self::isObject($piece)) {
                continue;
            }
            $p = self::props($piece);
            $name = is_string($p['name'] ?? null) ? $p['name'] : 'a piece';
            if (self::isObject($p['lifted'] ?? null)) {
                $liftedNames[] = $name;
                $game = self::props($p['lifted'])['game'] ?? null;
                $gameName = self::isObject($game) ? (self::props($game)['name'] ?? null) : null;
                $label = is_string($gameName) ? $gameName : 'a game';
                if (! in_array($label, $games, true)) {
                    $games[] = $label;
                }
            } elseif (is_string($p['meshId'] ?? null)) {
                if (self::isOwnMesh($p['ownMesh'] ?? null)) {
                    $ownNames[] = $name;
                } else {
                    $meshNames[] = $name;
                }
            }
        }

        if ($liftedNames !== []) {
            $what = count($liftedNames) === 1 ? 'a piece' : 'pieces';

            return 'This module holds '.$what.' from '.self::joinNames($games).': '.implode(', ', $liftedNames).'. Geometry lifted from a game cannot be uploaded to the hub.';
        }
        if ($meshNames !== []) {
            return 'This module holds meshes with no record of where they came from: '.implode(', ', $meshNames).'. Only meshes imported from your own model file can be shared.';
        }
        if ($ownNames !== [] && ! is_string($doc['geometry'] ?? null)) {
            return 'This module names meshes it does not carry: '.implode(', ', $ownNames).'.';
        }

        return null;
    }

    /**
     * "Open a mystery.json and know what it is." Takes a string to decode or an
     * already decoded value. Never throws.
     *
     * @return array{kind: string, version: int|float, compatibility: string, warnings: list<string>, game?: array{name?: string, shortname?: string}}
     */
    public static function identify(mixed $input): array
    {
        $value = is_string($input) ? self::decodeText($input) : $input;
        $unknown = ['kind' => 'unknown', 'version' => 0, 'compatibility' => 'unknown', 'warnings' => []];
        if (! self::isObject($value)) {
            return $unknown;
        }

        $container = self::asContainer($value);
        if ($container !== null) {
            if (! in_array($container->kind, self::KINDS, true)) {
                return [
                    'kind' => 'unknown',
                    'version' => $container->kindVersion,
                    'compatibility' => 'newer',
                    'warnings' => [self::newerWarning('unknown')],
                ];
            }
            $compatibility = self::compatibilityFor($container->kind, $container->container, $container->kindVersion);
            $warnings = [];
            if ($compatibility === 'newer') {
                $warnings[] = self::newerWarning($container->kind);
            }
            $actual = self::sniffPayloadKind($container->payload);
            if ($actual !== null && $actual !== $container->kind) {
                $warnings[] = 'This is labelled a '.$container->kind.' but its contents look like a '.$actual.'.';
            }
            $result = ['kind' => $container->kind, 'version' => $container->kindVersion, 'compatibility' => $compatibility, 'warnings' => $warnings];
            $game = GameIdentity::fromPayload($container->kind, $container->payload);
            if ($game !== null) {
                $result['game'] = $game;
            }

            return $result;
        }

        $legacy = self::identifyLegacy($value);
        if ($legacy !== null) {
            $compatibility = self::compatibilityFor($legacy['kind'], self::VERSION, $legacy['version']);
            $result = [
                'kind' => $legacy['kind'],
                'version' => $legacy['version'],
                'compatibility' => $compatibility,
                'warnings' => $compatibility === 'newer' ? [self::newerWarning($legacy['kind'])] : [],
            ];
            $game = GameIdentity::fromPayload($legacy['kind'], $legacy['payload']);
            if ($game !== null) {
                $result['game'] = $game;
            }

            return $result;
        }

        return $unknown;
    }

    /** A JavaScript `typeof x === "object" && x !== null`: an object or an array. */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || is_array($value);
    }

    /** The properties of a decoded object or array, as an array. @return array<array-key, mixed> */
    public static function props(mixed $value): array
    {
        return $value instanceof stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);
    }

    /** JavaScript's `String.prototype.trim`, which also strips Unicode spaces. */
    public static function trim(string $text): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $text);
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private static function inflateCode(string $code): ?string
    {
        $bytes = self::base64UrlToBytes(substr($code, strlen(self::COMPRESSED_CODE_PREFIX)));
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $json = @gzinflate($bytes, self::MAX_INFLATED_BYTES);

        return $json === false ? null : $json;
    }

    private static function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return implode('', $names);
        }

        return implode(', ', array_slice($names, 0, -1)).' and '.$names[count($names) - 1];
    }

    private static function isOwnMesh(mixed $value): bool
    {
        if (! self::isObject($value)) {
            return false;
        }
        $file = self::props($value)['file'] ?? null;

        return is_string($file) && $file !== '';
    }

    private static function compatibilityFor(string $kind, int|float $containerVersion, int|float $kindVersion): string
    {
        if ($containerVersion > self::VERSION) {
            return 'newer';
        }
        if ($kindVersion > self::SUPPORTED_KIND_VERSIONS[$kind]) {
            return 'newer';
        }

        return 'ok';
    }

    private static function newerWarning(string $kind): string
    {
        $noun = $kind === 'unknown' ? 'file' : $kind;

        return 'This '.$noun.' was made by a newer version of coilbox. Update coilbox to open it.';
    }

    /** @return array{kind: string, version: int|float, payload: mixed}|null */
    private static function identifyLegacy(mixed $value): ?array
    {
        if (! self::isObject($value)) {
            return null;
        }
        $v = self::props($value);
        $version = self::isNumber($v['formatVersion'] ?? null) ? $v['formatVersion'] : 1;
        $format = $v['format'] ?? null;
        if ($format === 'coilbox-campaign') {
            return ['kind' => 'campaign', 'version' => $version, 'payload' => $v['campaign'] ?? null];
        }
        if ($format === 'coilbox-challenge') {
            return ['kind' => 'challenge', 'version' => $version, 'payload' => $value];
        }
        if ($format === 'coilbox-pack') {
            return ['kind' => 'setup-pack', 'version' => $version, 'payload' => $v['settings'] ?? null];
        }
        if (self::sniffPayloadKind($value) === 'preset') {
            return ['kind' => 'preset', 'version' => 1, 'payload' => $value];
        }

        return null;
    }
}
