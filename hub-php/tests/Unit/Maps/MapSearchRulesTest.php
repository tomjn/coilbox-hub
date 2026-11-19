<?php

namespace Tests\Unit\Maps;

use App\Domain\Maps\Authors;
use App\Supabase\Local\EnglishStemmer;
use App\Supabase\Local\TextSearch;
use PHPUnit\Framework\TestCase;

/**
 * The rules maps are keyed and searched by after their move (#599). Every
 * expected value here is what Postgres 17 answered for the same input on
 * 19 November 2026: `author_key` and `author_credits` from production's
 * definitions, `ts_lexize('english_stem', ...)`, `to_tsvector('english', ...)`
 * and `websearch_to_tsquery('english', ...)`.
 */
class MapSearchRulesTest extends TestCase
{
    public function test_an_author_key_drops_clan_tags_and_unicode_punctuation_only(): void
    {
        $this->assertSame('beherith', Authors::key(' (BAR) Beherith! '));
        $this->assertSame('beherith', Authors::key('[teh]Beherith'));
        $this->assertSame('beherith', Authors::key('Beherith (mysterme@gmail.com)'));
        $this->assertSame('two words', Authors::key('{CLAN}  Two   Words ...'));
        $this->assertSame('ünïcödé–name', Authors::key('Ünïcödé–Name—'));
        $this->assertSame('', Authors::key('[only tag]'));
        $this->assertSame('', Authors::key(null));
        // Symbols are not punctuation.
        $this->assertSame('~x~', Authors::key('~x~'));
        $this->assertSame('€x€', Authors::key('€x€'));
        $this->assertSame('ēmoji 😀 name 😀', Authors::key('Ēmoji 😀 name 😀'));
        $this->assertSame('x', Authors::key('§x§'));
    }

    public function test_credits_split_on_ampersands_pluses_commas_and_the_word_and(): void
    {
        $this->assertSame(['a', 'b', 'c', 'd', 'e andy Sandman'], Authors::credits('a & b and c, d+e andy Sandman'));
        $this->assertSame(['Nixa', '[teh]Beherith'], Authors::credits('Nixa and [teh]Beherith'));
        $this->assertSame(['ANDY', 'Andrew'], Authors::credits('ANDY and Andrew'));
        $this->assertSame([], Authors::credits('   '));
        $this->assertSame([], Authors::credits(null));
    }

    public function test_a_merge_resolves_one_step(): void
    {
        $this->assertSame('beherith', Authors::resolve('beh', ['beh' => 'beherith']));
        $this->assertSame('icexuick', Authors::resolve('icexuick', ['beh' => 'beherith']));
    }

    public function test_the_stemmer_matches_postgres(): void
    {
        $stems = [
            'running' => 'run', 'glitters' => 'glitter', 'desolated' => 'desol', 'castles' => 'castl', 'remade' => 'remad',
            'certified' => 'certifi', 'generously' => 'generous', 'communication' => 'communic', 'skies' => 'sky', 'dying' => 'die',
            'news' => 'news', 'ties' => 'tie', 'cries' => 'cri', 'gaps' => 'gap', 'agreed' => 'agre', 'hopping' => 'hop',
            'hoped' => 'hope', 'by' => 'by', 'say' => 'say', 'rational' => 'ration', 'feudalism' => 'feudal', 'hopefulness' => 'hope',
            'electrical' => 'electr', 'inning' => 'inning', 'mountainous' => 'mountain', 'islands' => 'island', 'dried' => 'dri',
            'tidal' => 'tidal', 'windy' => 'windi', 'geothermal' => 'geotherm', 'nikuksis' => 'nikuksi',
        ];
        foreach ($stems as $word => $stem) {
            $this->assertSame($stem, EnglishStemmer::stem($word), $word);
        }
    }

    public function test_a_document_is_indexed_as_postgres_indexes_it(): void
    {
        $this->assertSame(
            [['origin', 1], ['beherith/the_yak', 3], ['glitter', 6], ['v2.2', 7], ['4.0.1', 8]],
            TextSearch::lexemes('original from Beherith/The_Yak All That Glitters v2.2 4.0.1'),
        );
        $this->assertSame(
            [['running-wat', 1], ['run', 2], ['water', 3], ['16', 4], ['way', 5], ['2', 6], ['-4', 7], ['sd', 8], ['-129', 9]],
            TextSearch::lexemes('running-water 16-way 2-4 SD-129'),
        );
        $this->assertSame(
            [['1v1-3v3', 1], ['1v1', 2], ['3v3', 3], ['mysterme@gmail.com', 4], ['castl', 5], ['x40', 6], ['v2.4', 7], ['+30', 8]],
            TextSearch::lexemes('1v1-3v3 mysterme@gmail.com Castles_x40_V2.4 +30'),
        );
        // Positions run on from one part to the next.
        $this->assertSame(['comet' => [1, 3], 'catcher' => [2], 'beherith' => [4]], TextSearch::vector(['Comet Catcher', null, 'Comet', 'Beherith']));
    }

    public function test_websearch_queries(): void
    {
        $vector = TextSearch::vector(['All That Glitters v2.2', 'All That Glitters v2.2', 'Search for the gold in this desolated red desert', 'Beherith']);
        $this->assertTrue(TextSearch::matches($vector, 'glittering'));
        $this->assertTrue(TextSearch::matches($vector, 'v2.2'));
        $this->assertFalse(TextSearch::matches($vector, 'v2'));
        $this->assertTrue(TextSearch::matches($vector, '"red desert"'));
        $this->assertFalse(TextSearch::matches($vector, '"desert red"'));
        $this->assertTrue(TextSearch::matches($vector, 'snow or desert'));
        $this->assertFalse(TextSearch::matches($vector, 'gold -beherith'));
        $this->assertTrue(TextSearch::matches($vector, 'gold -water'));
        // Nothing searchable left matches nothing.
        $this->assertFalse(TextSearch::matches($vector, 'the'));
        $this->assertFalse(TextSearch::matches($vector, ''));
    }
}
