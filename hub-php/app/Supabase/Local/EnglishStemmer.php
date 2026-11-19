<?php

namespace App\Supabase\Local;

/**
 * The Snowball English (Porter2) stemmer, which Postgres's `english` text
 * search configuration uses. Takes one lower case word.
 */
final class EnglishStemmer
{
    private const EXCEPTIONS = [
        'skis' => 'ski', 'skies' => 'sky', 'dying' => 'die', 'lying' => 'lie', 'tying' => 'tie', 'idly' => 'idl',
        'gently' => 'gentl', 'ugly' => 'ugli', 'early' => 'earli', 'only' => 'onli', 'singly' => 'singl',
        'sky' => 'sky', 'news' => 'news', 'howe' => 'howe', 'atlas' => 'atlas', 'cosmos' => 'cosmos', 'bias' => 'bias',
        'andes' => 'andes',
    ];

    private const AFTER_1A = ['inning', 'outing', 'canning', 'herring', 'earring', 'proceed', 'exceed', 'succeed'];

    private const STEP2 = [
        'ization' => 'ize', 'ational' => 'ate', 'fulness' => 'ful', 'ousness' => 'ous', 'iveness' => 'ive',
        'tional' => 'tion', 'biliti' => 'ble', 'lessli' => 'less', 'entli' => 'ent', 'ation' => 'ate', 'alism' => 'al',
        'aliti' => 'al', 'ousli' => 'ous', 'iviti' => 'ive', 'fulli' => 'ful', 'enci' => 'ence', 'anci' => 'ance',
        'abli' => 'able', 'izer' => 'ize', 'ator' => 'ate', 'alli' => 'al', 'bli' => 'ble', 'ogi' => 'og', 'li' => '',
    ];

    private const STEP3 = [
        'ational' => 'ate', 'tional' => 'tion', 'alize' => 'al', 'icate' => 'ic', 'iciti' => 'ic', 'ative' => '',
        'ical' => 'ic', 'ness' => '', 'ful' => '',
    ];

    private const STEP4 = [
        'ement', 'ance', 'ence', 'able', 'ible', 'ment', 'ant', 'ent', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize', 'ion',
        'al', 'er', 'ic',
    ];

    public static function stem(string $word): string
    {
        if (strlen($word) <= 2) {
            return $word;
        }
        if (isset(self::EXCEPTIONS[$word])) {
            return self::EXCEPTIONS[$word];
        }
        if ($word[0] === "'") {
            $word = substr($word, 1);
        }
        if (str_starts_with($word, 'y')) {
            $word = 'Y'.substr($word, 1);
        }
        $word = preg_replace('/(?<=[aeiouy])y/', 'Y', $word);

        [$r1, $r2] = self::regions($word);

        $word = self::step0($word);
        $word = self::step1a($word);
        if (in_array($word, self::AFTER_1A, true)) {
            return $word;
        }
        $word = self::step1b($word, $r1);
        $word = self::step1c($word);
        $word = self::step2($word, $r1);
        $word = self::step3($word, $r1, $r2);
        $word = self::step4($word, $r2);
        $word = self::step5($word, $r1, $r2);

        return str_replace('Y', 'y', $word);
    }

    private static function vowel(string $char): bool
    {
        return str_contains('aeiouy', $char);
    }

    /** @return array{0: int, 1: int} where R1 and R2 start */
    private static function regions(string $word): array
    {
        $length = strlen($word);
        $r1 = $length;
        if (preg_match('/^(gener|commun|arsen)/', $word, $m)) {
            $r1 = strlen($m[1]);
        } else {
            for ($i = 1; $i < $length; $i++) {
                if (! self::vowel($word[$i]) && self::vowel($word[$i - 1])) {
                    $r1 = $i + 1;
                    break;
                }
            }
        }
        $r2 = $length;
        for ($i = $r1 + 1; $i < $length; $i++) {
            if (! self::vowel($word[$i]) && self::vowel($word[$i - 1])) {
                $r2 = $i + 1;
                break;
            }
        }

        return [$r1, $r2];
    }

    private static function inRegion(string $word, string $suffix, int $region): bool
    {
        return strlen($word) - strlen($suffix) >= $region;
    }

    private static function step0(string $word): string
    {
        foreach (["'s'", "'s", "'"] as $suffix) {
            if (str_ends_with($word, $suffix)) {
                return substr($word, 0, -strlen($suffix));
            }
        }

        return $word;
    }

    private static function step1a(string $word): string
    {
        if (str_ends_with($word, 'sses')) {
            return substr($word, 0, -2);
        }
        if (str_ends_with($word, 'ied') || str_ends_with($word, 'ies')) {
            return strlen($word) > 4 ? substr($word, 0, -2) : substr($word, 0, -1);
        }
        if (str_ends_with($word, 'us') || str_ends_with($word, 'ss')) {
            return $word;
        }
        if (str_ends_with($word, 's')) {
            $before = substr($word, 0, -2);
            if (preg_match('/[aeiouy]/', $before)) {
                return substr($word, 0, -1);
            }
        }

        return $word;
    }

    private static function step1b(string $word, int $r1): string
    {
        foreach (['eedly', 'ingly', 'edly', 'eed', 'ing', 'ed'] as $suffix) {
            if (! str_ends_with($word, $suffix)) {
                continue;
            }
            if ($suffix === 'eed' || $suffix === 'eedly') {
                return self::inRegion($word, $suffix, $r1) ? substr($word, 0, -strlen($suffix)).'ee' : $word;
            }
            $stem = substr($word, 0, -strlen($suffix));
            if (! preg_match('/[aeiouy]/', $stem)) {
                return $word;
            }
            if (preg_match('/(at|bl|iz)$/', $stem)) {
                return $stem.'e';
            }
            if (preg_match('/(bb|dd|ff|gg|mm|nn|pp|rr|tt)$/', $stem)) {
                return substr($stem, 0, -1);
            }
            if (self::isShort($stem)) {
                return $stem.'e';
            }

            return $stem;
        }

        return $word;
    }

    private static function step1c(string $word): string
    {
        $length = strlen($word);
        if ($length > 2 && ($word[$length - 1] === 'y' || $word[$length - 1] === 'Y') && ! self::vowel($word[$length - 2])) {
            return substr($word, 0, -1).'i';
        }

        return $word;
    }

    private static function step2(string $word, int $r1): string
    {
        foreach (self::STEP2 as $suffix => $replacement) {
            if (! str_ends_with($word, $suffix)) {
                continue;
            }
            if (! self::inRegion($word, $suffix, $r1)) {
                return $word;
            }
            $stem = substr($word, 0, -strlen($suffix));
            if ($suffix === 'ogi') {
                return str_ends_with($stem, 'l') ? $stem.'og' : $word;
            }
            if ($suffix === 'li') {
                return preg_match('/[cdeghkmnrt]$/', $stem) ? $stem : $word;
            }

            return $stem.$replacement;
        }

        return $word;
    }

    private static function step3(string $word, int $r1, int $r2): string
    {
        foreach (self::STEP3 as $suffix => $replacement) {
            if (! str_ends_with($word, $suffix)) {
                continue;
            }
            if (! self::inRegion($word, $suffix, $r1)) {
                return $word;
            }
            if ($suffix === 'ative' && ! self::inRegion($word, $suffix, $r2)) {
                return $word;
            }

            return substr($word, 0, -strlen($suffix)).$replacement;
        }

        return $word;
    }

    private static function step4(string $word, int $r2): string
    {
        foreach (self::STEP4 as $suffix) {
            if (! str_ends_with($word, $suffix)) {
                continue;
            }
            if (! self::inRegion($word, $suffix, $r2)) {
                return $word;
            }
            $stem = substr($word, 0, -strlen($suffix));
            if ($suffix === 'ion') {
                return preg_match('/[st]$/', $stem) ? $stem : $word;
            }

            return $stem;
        }

        return $word;
    }

    private static function step5(string $word, int $r1, int $r2): string
    {
        if (str_ends_with($word, 'e')) {
            $stem = substr($word, 0, -1);
            if (self::inRegion($word, 'e', $r2) || (self::inRegion($word, 'e', $r1) && ! self::endsShortSyllable($stem))) {
                return $stem;
            }

            return $word;
        }
        if (str_ends_with($word, 'll') && self::inRegion($word, 'l', $r2)) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    private static function endsShortSyllable(string $word): bool
    {
        $length = strlen($word);
        if ($length === 2) {
            return self::vowel($word[0]) && ! self::vowel($word[1]);
        }
        if ($length < 3) {
            return false;
        }
        $last = $word[$length - 1];

        return ! self::vowel($word[$length - 3]) && self::vowel($word[$length - 2]) && ! self::vowel($last)
            && ! in_array($last, ['w', 'x', 'Y'], true);
    }

    private static function isShort(string $word): bool
    {
        return self::endsShortSyllable($word) && self::regions($word)[0] >= strlen($word);
    }
}
