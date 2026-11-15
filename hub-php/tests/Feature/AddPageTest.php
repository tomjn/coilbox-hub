<?php

namespace Tests\Feature;

use Tests\TestCase;

/** `/add`, the page that says games and maps come from Coilbox. */
class AddPageTest extends TestCase
{
    public function test_it_explains_sending_from_coilbox_and_claiming(): void
    {
        $this->get('/add')
            ->assertOk()
            ->assertSee('<title>Add your game or map', false)
            ->assertSee('Share your maps and games')
            ->assertSee('Request ownership')
            ->assertSee('href="/sign-in"', false);
    }
}
