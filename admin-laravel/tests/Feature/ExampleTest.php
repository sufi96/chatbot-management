<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The dashboard has been behind auth since the first commit, so the stock
     * skeleton assertion of a 200 here never held. A guest is redirected.
     */
    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_login_screen_is_reachable(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
