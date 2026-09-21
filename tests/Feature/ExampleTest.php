<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_guests_to_login()
    {
        // routes/modules/web-shared.php: root is no longer the starter
        // kit's marketing "Welcome" page — it redirects straight into the
        // real app (dashboard when authenticated, login otherwise).
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }
}
