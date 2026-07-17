<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutExceptionHandling();

        $user = User::factory()->create(['email' => 'admin@admin.com']);
        Sanctum::actingAs($user, [], 'web');
    }

    public function test_it_gets_users_list(): void
    {
        $users = User::factory()->count(5)->create();

        $response = $this->get(route('api.users.index'));

        $response->assertOk()->assertSee($users[0]->name);
    }

    public function test_it_stores_the_user(): void
    {
        $data = User::factory()->make()->toArray();
        $data['password'] = Str::random(8);

        $response = $this->postJson(route('api.users.store'), $data);

        unset($data['password']);
        unset($data['email_verified_at']);
        unset($data['created_at']);
        unset($data['updated_at']);

        $this->assertDatabaseHas('users', $data);
        $response->assertStatus(201)->assertJsonFragment($data);
    }

    public function test_it_updates_the_user(): void
    {
        $user = User::factory()->create();

        $data = [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Str::random(8),
        ];

        $response = $this->putJson(route('api.users.update', $user), $data);

        unset($data['password']);
        $data['id'] = $user->id;

        $this->assertDatabaseHas('users', $data);
        $response->assertStatus(200)->assertJsonFragment($data);
    }

    public function test_it_deletes_the_user(): void
    {
        $user = User::factory()->create();

        $response = $this->deleteJson(route('api.users.destroy', $user));

        $this->assertModelMissing($user);
        $response->assertNoContent();
    }
}
