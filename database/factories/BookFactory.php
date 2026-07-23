<?php

namespace Database\Factories;

use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'author' => fake()->name(),
            'isbn' => fake()->unique()->numerify('978##########'),
            'description' => fake()->paragraph(),
            'published_year' => fake()->numberBetween(1900, (int) now()->format('Y')),
            'total_copies' => 3,
            'available_copies' => 3,
        ];
    }
}
