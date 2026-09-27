<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'role' => User::ROLE_MEMBER,
            'status' => User::STATUS_ACTIVE,
            'password' => 'secret123',
        ];
    }

    /** کاربر به همراه شخص متصل که یک‌بار وارد شده (حساب فعال) */
    public function withPerson(array $personAttributes = []): static
    {
        return $this->afterCreating(function (User $user) use ($personAttributes) {
            $person = Person::factory()->create($personAttributes + ['created_by' => $user->id]);
            $user->person_id = $person->id;
            $user->last_login_at = now();
            $user->save();
        });
    }

    public function admin(): static
    {
        return $this->state(['role' => User::ROLE_SUPER_ADMIN]);
    }
}
