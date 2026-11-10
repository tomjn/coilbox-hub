<?php

namespace Tests\Unit;

use App\Domain\Account\AccountRules;
use PHPUnit\Framework\TestCase;

/** Cases from lib/account/*.test.ts. */
class AccountRulesTest extends TestCase
{
    public function test_a_display_name_is_trimmed_and_its_spaces_collapsed(): void
    {
        $this->assertSame(['name' => 'Tom J Nowell'], AccountRules::checkDisplayName("  Tom   J\tNowell "));
    }

    public function test_a_display_name_must_be_two_to_forty_characters(): void
    {
        $this->assertArrayHasKey('error', AccountRules::checkDisplayName('a'));
        $this->assertArrayHasKey('error', AccountRules::checkDisplayName(' a '));
        $this->assertArrayHasKey('error', AccountRules::checkDisplayName(str_repeat('a', 41)));
        $this->assertSame(['name' => str_repeat('é', 40)], AccountRules::checkDisplayName(str_repeat('é', 40)));
        $this->assertArrayHasKey('error', AccountRules::checkDisplayName(null));
    }

    public function test_rename_errors_name_the_database_code(): void
    {
        $this->assertStringContainsString('already in use', AccountRules::renameErrorMessage('23505'));
        $this->assertStringContainsString('between 2 and 40', AccountRules::renameErrorMessage('23514'));
        $this->assertSame('Could not save your display name. Try again.', AccountRules::renameErrorMessage(null));
    }

    public function test_deleting_needs_the_name_exactly_apart_from_outer_space(): void
    {
        $this->assertTrue(AccountRules::confirmsDeletion(' Tom ', 'Tom'));
        $this->assertFalse(AccountRules::confirmsDeletion('tom', 'Tom'));
        $this->assertFalse(AccountRules::confirmsDeletion('', ''));
        $this->assertFalse(AccountRules::confirmsDeletion(null, 'Tom'));
    }

    public function test_an_identity_is_named_by_its_first_usable_field(): void
    {
        $this->assertSame('tomjn', AccountRules::identityAccount(['identity_data' => ['preferred_username' => 'tomjn', 'email' => 'x@y']]));
        $this->assertSame('x@y', AccountRules::identityAccount(['identity_data' => ['name' => '  ', 'email' => 'x@y']]));
        $this->assertNull(AccountRules::identityAccount(['identity_data' => []]));
    }

    public function test_only_providers_not_yet_linked_are_offered(): void
    {
        $this->assertSame(['github' => 'GitHub', 'twitch' => 'Twitch'], AccountRules::linkableProviders([['provider' => 'discord']]));
    }

    public function test_a_code_is_digits_with_spaces_removed(): void
    {
        $this->assertSame('123456', AccountRules::readCode('123 456'));
        $this->assertNull(AccountRules::readCode('12a456'));
        $this->assertNull(AccountRules::readCode(['123456']));
    }
}
