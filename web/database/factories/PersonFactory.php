<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    private const MALE = ['محمد', 'علی', 'حسین', 'رضا', 'مهدی', 'حسن', 'امیر', 'جواد', 'مصطفی', 'اکبر'];

    private const FEMALE = ['فاطمه', 'زهرا', 'مریم', 'زینب', 'معصومه', 'سکینه', 'نرگس', 'طاهره', 'لیلا', 'سارا'];

    private const LAST = ['احمدی', 'محمدی', 'حسینی', 'رضایی', 'کریمی', 'موسوی', 'جعفری', 'صادقی'];

    public function definition(): array
    {
        $gender = $this->faker->randomElement(['m', 'f']);

        return [
            'first_name' => $this->faker->randomElement($gender === 'm' ? self::MALE : self::FEMALE),
            'last_name' => $this->faker->randomElement(self::LAST),
            'gender' => $gender,
            'birth_date' => (string) $this->faker->numberBetween(1300, 1395),
            'is_deceased' => false,
        ];
    }

    public function male(): static
    {
        return $this->state(fn () => ['gender' => 'm', 'first_name' => $this->faker->randomElement(self::MALE)]);
    }

    public function female(): static
    {
        return $this->state(fn () => ['gender' => 'f', 'first_name' => $this->faker->randomElement(self::FEMALE)]);
    }

    public function deceased(): static
    {
        return $this->state(['is_deceased' => true]);
    }
}
