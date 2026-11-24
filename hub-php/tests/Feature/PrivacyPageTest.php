<?php

namespace Tests\Feature;

use Tests\TestCase;

/** `/privacy`, what the hub keeps about people and who to ask about it. */
class PrivacyPageTest extends TestCase
{
    public function test_it_names_who_to_contact_and_what_deleting_an_account_does(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('<title>Privacy', false)
            ->assertSee('Tom J Nowell')
            ->assertSee('href="mailto:admin@tomjn.com"', false)
            ->assertSee('Delete your account')
            ->assertSee('Information Commissioner')
            ->assertSee('href="/account"', false);
    }

    public function test_every_page_links_to_it_from_the_footer(): void
    {
        $this->get('/add')->assertOk()->assertSee('href="/privacy"', false);
    }
}
