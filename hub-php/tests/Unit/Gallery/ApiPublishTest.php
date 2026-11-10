<?php

namespace Tests\Unit\Gallery;

use App\Domain\Container\Json;
use App\Domain\Gallery\ApiPublish;
use PHPUnit\Framework\TestCase;

/** Ported from `lib/api/publish.test.ts`. */
class ApiPublishTest extends TestCase
{
    private function parse(string $json): array
    {
        return ApiPublish::parseBody(Json::decode($json));
    }

    private function assertRejected(string $error, array $result): void
    {
        $this->assertFalse($result['ok']);
        $this->assertSame($error, $result['error']);
    }

    public function test_a_well_formed_publish_body_parses(): void
    {
        $result = $this->parse('{"code": "some-code", "title": "Title", "description": "A description", "tags": ["eco", "rush"]}');

        $this->assertTrue($result['ok']);
        $this->assertSame([
            'code' => 'some-code',
            'title' => 'Title',
            'description' => 'A description',
            'tags' => ['eco', 'rush'],
        ], $result['fields']);
    }

    public function test_description_and_tags_are_optional_and_default_to_empty(): void
    {
        $result = $this->parse('{"code": "some-code", "title": "Title"}');

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['fields']['description']);
        $this->assertSame([], $result['fields']['tags']);
    }

    public function test_a_non_object_body_is_rejected(): void
    {
        $this->assertRejected('The request body must be a JSON object.', $this->parse('"just a string"'));
    }

    public function test_an_array_body_is_rejected(): void
    {
        $this->assertRejected('The request body must be a JSON object.', $this->parse('[1, 2, 3]'));
    }

    public function test_null_is_rejected(): void
    {
        $this->assertRejected('The request body must be a JSON object.', ApiPublish::parseBody(null));
    }

    public function test_an_unknown_field_is_rejected_rather_than_silently_ignored(): void
    {
        $this->assertRejected('Unknown field: author_name', $this->parse('{"code": "c", "title": "t", "author_name": "someone else"}'));
    }

    public function test_a_missing_code_is_rejected(): void
    {
        $this->assertRejected('`code` is required and must be a string.', $this->parse('{"title": "Title"}'));
    }

    public function test_a_blank_code_is_rejected(): void
    {
        $this->assertRejected('`code` is required and must be a string.', $this->parse('{"code": "   ", "title": "Title"}'));
    }

    public function test_a_non_string_code_is_rejected(): void
    {
        $this->assertRejected('`code` is required and must be a string.', $this->parse('{"code": 123, "title": "Title"}'));
    }

    public function test_a_missing_title_is_rejected(): void
    {
        $this->assertRejected('`title` is required and must be a string.', $this->parse('{"code": "c"}'));
    }

    public function test_a_non_string_description_is_rejected(): void
    {
        $this->assertRejected('`description` must be a string.', $this->parse('{"code": "c", "title": "t", "description": 5}'));
    }

    public function test_non_array_tags_are_rejected(): void
    {
        $this->assertRejected('`tags` must be an array of strings.', $this->parse('{"code": "c", "title": "t", "tags": "eco,rush"}'));
    }

    public function test_a_tags_array_with_a_non_string_entry_is_rejected(): void
    {
        $this->assertRejected('`tags` must be an array of strings.', $this->parse('{"code": "c", "title": "t", "tags": ["eco", 5]}'));
    }

    public function test_every_publish_failure_status_maps_to_the_right_http_status(): void
    {
        $this->assertSame(422, ApiPublish::statusForFailure('invalid'));
        $this->assertSame(429, ApiPublish::statusForFailure('rate_limited'));
        $this->assertSame(500, ApiPublish::statusForFailure('storage_error'));
    }
}
