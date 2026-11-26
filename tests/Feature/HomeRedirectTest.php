<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_from_home_to_the_login_screen()
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_signed_in_users_are_sent_from_home_to_their_current_dashboard()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('dashboard', $user->currentTeam->slug));
    }
}
