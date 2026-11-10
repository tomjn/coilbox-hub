<?php

namespace Tests\Unit\Gallery;

use App\Domain\Gallery\Feed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Ported from the Next app's `lib/feed/atom.test.ts` and `lib/feed/gallery.test.ts`. */
class FeedTest extends TestCase
{
    private const ORIGIN = 'https://hub.example';

    private function entry(array $over = []): array
    {
        return array_merge([
            'id' => 'urn:uuid:11111111-1111-4111-8111-111111111111',
            'title' => 'Fast start',
            'link' => 'https://hub.example/item/11111111-1111-4111-8111-111111111111',
            'published' => '2026-10-01T10:00:00.000Z',
            'updated' => '2026-10-02T10:00:00.000Z',
            'author' => 'Ada',
            'summary' => 'Opens with two builders.',
            'category' => ['term' => 'preset', 'label' => 'Preset'],
        ], $over);
    }

    private function feed(array $over = []): array
    {
        return array_merge([
            'id' => 'https://hub.example/feed.xml',
            'title' => 'Coilbox Hub',
            'subtitle' => 'New things people made in Coilbox.',
            'selfUrl' => 'https://hub.example/feed.xml',
            'alternateUrl' => 'https://hub.example/gallery',
            'updated' => '2026-10-02T10:00:00.000Z',
            'author' => 'Coilbox Hub',
            'entries' => [$this->entry()],
        ], $over);
    }

    private function row(array $over = []): array
    {
        return array_merge([
            'id' => '11111111-1111-4111-8111-111111111111',
            'kind' => 'preset',
            'mode' => null,
            'title' => 'Fast start',
            'description' => 'Opens with two builders.',
            'author_name' => 'Ada',
            'created_at' => '2026-10-01T10:00:00+00:00',
            'updated_at' => '2026-10-03T09:00:00.5+00:00',
        ], $over);
    }

    /** @return list<string> */
    private function entriesOf(string $xml): array
    {
        preg_match_all('/<entry>[\s\S]*?<\/entry>/', $xml, $m);

        return $m[0];
    }

    /** A stack check. Every opened element must close in order. */
    private function assertBalanced(string $xml): void
    {
        $xml = preg_replace('/<\?[\s\S]*?\?>/', '', $xml, 1);
        preg_match_all('/<(\/?)([A-Za-z][\w:-]*)[^>]*?(\/?)>/', $xml, $all, PREG_SET_ORDER);
        $stack = [];
        foreach ($all as [, $close, $name, $self]) {
            if ($self !== '') {
                continue;
            }
            if ($close !== '') {
                $this->assertSame(array_pop($stack), $name);
            } else {
                $stack[] = $name;
            }
        }
        $this->assertSame([], $stack);
    }

    public function test_text_escapes_ampersands_angle_brackets_and_quotes(): void
    {
        $this->assertSame(
            'Tom &amp; Jerry &lt;b&gt;&quot;hi&quot;&lt;/b&gt; it&apos;s',
            Feed::escapeText('Tom & Jerry <b>"hi"</b> it\'s'),
        );
    }

    public function test_an_ampersand_is_escaped_once_not_twice(): void
    {
        $this->assertSame('&amp;amp;', Feed::escapeText('&amp;'));
    }

    public function test_a_closing_cdata_marker_in_a_title_cannot_survive(): void
    {
        $out = Feed::escapeText('a ]]> b');
        $this->assertStringNotContainsString(']]>', $out);
        $this->assertSame('a ]]&gt; b', $out);
    }

    public function test_characters_illegal_in_xml_are_removed(): void
    {
        $this->assertSame('abcdefgh', Feed::escapeText("a\u{0}b\u{8}c\u{B}d\u{C}e\u{1F}f\u{FFFE}g\u{FFFF}h"));
    }

    public function test_a_pair_is_kept(): void
    {
        // A lone surrogate cannot exist in a PHP UTF-8 string, so only the pair case ports.
        $this->assertSame('rocket 🚀', Feed::escapeText('rocket 🚀'));
    }

    public function test_tab_newline_and_carriage_return_stay_in_text(): void
    {
        $this->assertSame("a\tb\nc\rd", Feed::escapeText("a\tb\nc\rd"));
    }

    public function test_non_ascii_text_passes_through_unchanged(): void
    {
        $this->assertSame('Café Ünïcode 日本語', Feed::escapeText('Café Ünïcode 日本語'));
    }

    public function test_an_attribute_keeps_its_quotes_from_ending_the_value(): void
    {
        $out = Feed::escapeAttribute('a "quoted" \'word\' & <tag>');
        $this->assertDoesNotMatchRegularExpression('/["\'<>]/', $out);
        $this->assertSame('a &quot;quoted&quot; &apos;word&apos; &amp; &lt;tag&gt;', $out);
    }

    public function test_an_attribute_keeps_a_line_break_from_turning_into_a_space(): void
    {
        $this->assertSame('a&#10;b&#9;c&#13;d', Feed::escapeAttribute("a\nb\tc\rd"));
    }

    public function test_a_date_becomes_rfc3339_in_utc(): void
    {
        $this->assertSame('2026-10-04T10:30:00.000Z', Feed::rfc3339('2026-10-04T12:30:00+02:00'));
        $this->assertSame('2026-10-04T12:30:00.123Z', Feed::rfc3339('2026-10-04T12:30:00.123456+00:00'));
    }

    public function test_a_date_that_does_not_parse_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Feed::rfc3339('not a date');
    }

    public function test_the_document_declares_xml_and_the_atom_namespace(): void
    {
        $xml = Feed::document($this->feed());
        $this->assertTrue(str_starts_with($xml, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<feed xmlns=\"http://www.w3.org/2005/Atom\">"));
        $this->assertTrue(str_ends_with(rtrim($xml), '</feed>'));
    }

    public function test_the_feed_carries_id_title_updated_a_self_link_and_an_author(): void
    {
        $xml = Feed::document($this->feed());
        $head = substr($xml, 0, strpos($xml, '<entry>'));
        $this->assertStringContainsString('<id>https://hub.example/feed.xml</id>', $head);
        $this->assertStringContainsString('<title type="text">Coilbox Hub</title>', $head);
        $this->assertStringContainsString('<updated>2026-10-02T10:00:00.000Z</updated>', $head);
        $this->assertStringContainsString('<link rel="self" type="application/atom+xml" href="https://hub.example/feed.xml"/>', $head);
        $this->assertStringContainsString('<link rel="alternate" type="text/html" href="https://hub.example/gallery"/>', $head);
        $this->assertStringContainsString('<author><name>Coilbox Hub</name></author>', $head);
    }

    public function test_an_entry_carries_id_title_times_author_link_summary_and_category(): void
    {
        [$entry] = $this->entriesOf(Feed::document($this->feed()));
        $this->assertStringContainsString('<id>urn:uuid:11111111-1111-4111-8111-111111111111</id>', $entry);
        $this->assertStringContainsString('<title type="text">Fast start</title>', $entry);
        $this->assertStringContainsString('<published>2026-10-01T10:00:00.000Z</published>', $entry);
        $this->assertStringContainsString('<updated>2026-10-02T10:00:00.000Z</updated>', $entry);
        $this->assertStringContainsString('<author><name>Ada</name></author>', $entry);
        $this->assertStringContainsString('<link rel="alternate" type="text/html" href="https://hub.example/item/11111111-1111-4111-8111-111111111111"/>', $entry);
        $this->assertStringContainsString('<summary type="text">Opens with two builders.</summary>', $entry);
        $this->assertStringContainsString('<category term="preset" label="Preset"/>', $entry);
    }

    public function test_user_text_is_escaped_as_text_and_never_becomes_markup(): void
    {
        $xml = Feed::document($this->feed(['entries' => [$this->entry([
            'title' => '<script>alert(1)</script> & ]]>',
            'summary' => '<img src=x onerror="boom"> &lt;already&gt;',
            'author' => 'Mallory </name><name>x',
        ])]]));
        [$entry] = $this->entriesOf($xml);
        $this->assertStringNotContainsString('<script>', $entry);
        $this->assertStringNotContainsString('<img', $entry);
        $this->assertStringNotContainsString(']]>', $entry);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; ]]&gt;', $entry);
        $this->assertStringContainsString('&lt;img src=x onerror=&quot;boom&quot;&gt; &amp;lt;already&amp;gt;', $entry);
        $this->assertStringContainsString('<author><name>Mallory &lt;/name&gt;&lt;name&gt;x</name></author>', $entry);
        $this->assertBalanced($xml);
    }

    public function test_quotes_in_a_link_or_category_cannot_break_out_of_the_attribute(): void
    {
        $xml = Feed::document($this->feed(['entries' => [$this->entry([
            'link' => 'https://hub.example/item/1" onload="x',
            'category' => ['term' => 'a"b', 'label' => "c'd"],
        ])]]));
        [$entry] = $this->entriesOf($xml);
        $this->assertStringContainsString('href="https://hub.example/item/1&quot; onload=&quot;x"', $entry);
        $this->assertStringContainsString('<category term="a&quot;b" label="c&apos;d"/>', $entry);
        $this->assertBalanced($xml);
    }

    public function test_an_emoji_and_other_non_ascii_text_survive_in_a_title(): void
    {
        [$entry] = $this->entriesOf(Feed::document($this->feed(['entries' => [$this->entry(['title' => 'Rocket 🚀 café 日本'])]])));
        $this->assertStringContainsString('<title type="text">Rocket 🚀 café 日本</title>', $entry);
    }

    public function test_control_characters_never_reach_the_document(): void
    {
        $xml = Feed::document($this->feed(['entries' => [$this->entry(['title' => "a\u{0}b", 'summary' => "c\u{1}d"])]]));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $xml);
        $this->assertStringContainsString('>ab<', $xml);
        $this->assertStringContainsString('>cd<', $xml);
    }

    public function test_an_empty_description_leaves_the_summary_out(): void
    {
        [$entry] = $this->entriesOf(Feed::document($this->feed(['entries' => [$this->entry(['summary' => ''])]])));
        $this->assertStringNotContainsString('<summary', $entry);
        $this->assertStringContainsString('<link rel="alternate"', $entry);
    }

    public function test_a_description_of_only_control_characters_counts_as_empty(): void
    {
        [$entry] = $this->entriesOf(Feed::document($this->feed(['entries' => [$this->entry(['summary' => "\u{0}\u{1}"])]])));
        $this->assertStringNotContainsString('<summary', $entry);
    }

    public function test_a_missing_author_name_leaves_the_author_to_the_feed(): void
    {
        foreach ([null, '', '   '] as $author) {
            [$entry] = $this->entriesOf(Feed::document($this->feed(['entries' => [$this->entry(['author' => $author])]])));
            $this->assertStringNotContainsString('<author', $entry);
        }
    }

    public function test_an_empty_feed_is_a_valid_feed_with_no_entries(): void
    {
        $xml = Feed::document($this->feed(['entries' => []]));
        $this->assertSame([], $this->entriesOf($xml));
        $this->assertStringContainsString('<updated>2026-10-02T10:00:00.000Z</updated>', $xml);
        $this->assertBalanced($xml);
    }

    public function test_entries_keep_the_order_they_were_given(): void
    {
        $second = $this->entry(['id' => 'urn:uuid:22222222-2222-4222-8222-222222222222', 'title' => 'Second']);
        $entries = $this->entriesOf(Feed::document($this->feed(['entries' => [$this->entry(), $second]])));
        $this->assertCount(2, $entries);
        $this->assertStringContainsString('Fast start', $entries[0]);
        $this->assertStringContainsString('Second', $entries[1]);
    }

    public function test_a_full_document_is_balanced(): void
    {
        $this->assertBalanced(Feed::document($this->feed(['entries' => [
            $this->entry(),
            $this->entry(['id' => 'urn:uuid:x', 'author' => null, 'summary' => '']),
        ]])));
    }

    public function test_no_kind_means_the_whole_gallery(): void
    {
        $this->assertSame(['ok' => true, 'kind' => null], Feed::parseKind(null));
        $this->assertSame(['ok' => true, 'kind' => null], Feed::parseKind(''));
    }

    public function test_a_known_kind_is_accepted(): void
    {
        $this->assertSame(['ok' => true, 'kind' => 'preset'], Feed::parseKind('preset'));
        $this->assertSame(['ok' => true, 'kind' => 'mod-project'], Feed::parseKind('mod-project'));
    }

    public function test_an_unknown_kind_is_refused_with_the_kinds_that_are_known(): void
    {
        $result = Feed::parseKind('presets');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('preset, challenge, setup-pack, scenario, blueprint, mod-project', $result['error']);
    }

    public function test_kinds_that_only_exist_on_object_prototype_are_refused(): void
    {
        $this->assertFalse(Feed::parseKind('constructor')['ok']);
        $this->assertFalse(Feed::parseKind('__proto__')['ok']);
    }

    public function test_an_entry_id_is_the_items_uuid(): void
    {
        $xml = Feed::gallery([$this->row()], null, self::ORIGIN);
        $this->assertStringContainsString('<id>urn:uuid:11111111-1111-4111-8111-111111111111</id>', $xml);
    }

    public function test_an_entry_links_to_the_item_page_on_the_configured_origin(): void
    {
        $xml = Feed::gallery([$this->row()], null, self::ORIGIN);
        $this->assertStringContainsString('href="https://hub.example/item/11111111-1111-4111-8111-111111111111"', $xml);
    }

    public function test_the_feed_time_is_the_newest_entrys_update_not_the_clock(): void
    {
        $older = $this->row(['id' => '22222222-2222-4222-8222-222222222222', 'updated_at' => '2026-09-01T00:00:00Z']);
        $xml = Feed::gallery([$older, $this->row()], null, self::ORIGIN);
        $head = substr($xml, 0, strpos($xml, '<entry>'));
        $this->assertStringContainsString('<updated>2026-10-03T09:00:00.500Z</updated>', $head);
    }

    public function test_an_empty_feed_has_a_fixed_time_and_no_entries(): void
    {
        $xml = Feed::gallery([], null, self::ORIGIN);
        $this->assertStringNotContainsString('<entry>', $xml);
        $this->assertStringContainsString('<updated>1970-01-01T00:00:00.000Z</updated>', $xml);
    }

    public function test_the_feed_names_itself_and_a_narrowed_feed_says_which_kind(): void
    {
        $all = Feed::gallery([$this->row()], null, self::ORIGIN);
        $this->assertStringContainsString('<id>https://hub.example/feed.xml</id>', $all);
        $this->assertStringContainsString('<title type="text">Coilbox Hub</title>', $all);
        $this->assertStringContainsString('rel="alternate" type="text/html" href="https://hub.example/gallery"', $all);

        $presets = Feed::gallery([$this->row()], 'preset', self::ORIGIN);
        $this->assertStringContainsString('<id>https://hub.example/feed.xml?kind=preset</id>', $presets);
        $this->assertStringContainsString('<title type="text">Coilbox Hub: Presets</title>', $presets);
        $this->assertStringContainsString('href="https://hub.example/gallery?kind=preset"', $presets);
    }

    public function test_the_category_term_is_the_kind_and_the_label_is_what_a_player_calls_it(): void
    {
        $xml = Feed::gallery([$this->row(['kind' => 'challenge', 'mode' => 'warpath'])], null, self::ORIGIN);
        $this->assertStringContainsString('<category term="challenge" label="Warpath"/>', $xml);
    }

    public function test_a_blank_author_name_leaves_the_entry_to_the_feeds_author(): void
    {
        $xml = Feed::gallery([$this->row(['author_name' => ''])], null, self::ORIGIN);
        $entry = substr($xml, strpos($xml, '<entry>'));
        $this->assertStringNotContainsString('<author>', $entry);
    }

    public function test_the_feed_holds_the_newest_entries_and_no_more(): void
    {
        $rows = [];
        for ($i = 0; $i < Feed::ENTRIES + 5; $i++) {
            $rows[] = $this->row(['id' => sprintf('00000000-0000-4000-8000-%012d', $i)]);
        }
        $xml = Feed::gallery($rows, null, self::ORIGIN);
        $this->assertSame(Feed::ENTRIES, substr_count($xml, '<entry>'));
    }
}
