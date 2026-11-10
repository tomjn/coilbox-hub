<?php

namespace App\Domain\Container;

/**
 * How a container names the game it targets (Coilbox #1335), from the Next
 * app's `lib/container/gameIdentity.ts`. `name` is the exact archive name and
 * pins one build. `shortname` is the modinfo shortname and is stable across
 * releases. Either may be absent, never both.
 *
 * The shortname memory in `shortnames.ts` lives in the desktop app's local
 * storage and has nothing to remember on a server, so it is not ported.
 */
final class GameIdentity
{
    /** @return array{name?: string, shortname?: string}|null */
    public static function parse(mixed $value): ?array
    {
        if (is_string($value)) {
            $name = self::trimmed($value);

            return $name !== null ? ['name' => $name] : null;
        }
        if (! Container::isObject($value)) {
            return null;
        }
        $v = Container::props($value);
        $name = self::trimmed($v['name'] ?? null) ?? self::trimmed($v['gameName'] ?? null) ?? self::trimmed($v['pinnedName'] ?? null);
        $shortname = self::trimmed($v['shortname'] ?? null);
        if ($name === null && $shortname === null) {
            return null;
        }
        $identity = [];
        if ($name !== null) {
            $identity['name'] = $name;
        }
        if ($shortname !== null) {
            $identity['shortname'] = $shortname;
        }

        return $identity;
    }

    /**
     * The game a payload targets, whatever kind and whichever spelling it was
     * written with.
     *
     * @return array{name?: string, shortname?: string}|null
     */
    public static function fromPayload(string $kind, mixed $payload): ?array
    {
        if (! Container::isObject($payload)) {
            return null;
        }
        $p = Container::props($payload);

        $current = self::parse($p['game'] ?? null);
        if ($current !== null) {
            return $current;
        }

        switch ($kind) {
            case 'setup-pack':
                $games = $p['games'] ?? null;
                if (! is_array($games) || $games === []) {
                    return null;
                }

                return self::parse($games[0]);
            case 'preset':
            case 'mod-project':
                return self::parse($p['gameName'] ?? null);
            case 'challenge':
                $settings = $p['settings'] ?? null;

                return Container::isObject($settings) ? self::parse(Container::props($settings)['game'] ?? null) : null;
            case 'scenario':
                $scenario = $p['scenario'] ?? null;
                if (! Container::isObject($scenario)) {
                    return null;
                }
                $setup = Container::props($scenario)['setup'] ?? null;
                if (! Container::isObject($setup)) {
                    return null;
                }

                return self::parse(Container::props($setup)['gameName'] ?? null);
            case 'campaign':
                $document = Container::isObject($p['campaign'] ?? null) ? $p['campaign'] : $payload;

                return self::campaignDocument($document);
            default:
                return null;
        }
    }

    /** A campaign is only for one game when every mission's snapshot agrees. */
    private static function campaignDocument(mixed $document): ?array
    {
        if (! Container::isObject($document)) {
            return null;
        }
        $missions = Container::props($document)['missions'] ?? null;
        if (! is_array($missions)) {
            return null;
        }
        $names = [];
        foreach ($missions as $mission) {
            if (! Container::isObject($mission)) {
                continue;
            }
            $snapshot = Container::props($mission)['snapshot'] ?? null;
            if (! Container::isObject($snapshot)) {
                continue;
            }
            $name = self::trimmed(Container::props($snapshot)['gameName'] ?? null);
            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return count($names) === 1 ? ['name' => (string) array_key_first($names)] : null;
    }

    private static function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = Container::trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
