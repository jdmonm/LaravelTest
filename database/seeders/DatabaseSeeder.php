<?php

namespace Database\Seeders;

use App\Enums\LibraryRole;
use App\Models\Book;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(LibraryAuthorizationSeeder::class);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@library.test'],
            [
                'name' => 'Library Admin',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );
        $admin->syncRoles(LibraryRole::Admin);

        $librarian = User::query()->firstOrCreate(
            ['email' => 'librarian@library.test'],
            [
                'name' => 'Morgan Lee',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );
        $librarian->syncRoles(LibraryRole::Librarian);

        $member = User::query()->firstOrCreate(
            ['email' => 'member@library.test'],
            [
                'name' => 'Taylor Kim',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );
        $member->syncRoles(LibraryRole::Member);

        foreach (
            [
                [
                    'title' => 'The Left Hand of Darkness',
                    'author' => 'Ursula K. Le Guin',
                    'isbn' => '9780441478125',
                    'description' => 'A diplomatic journey across a world of winter and shifting identity.',
                    'published_year' => 1969,
                    'total_copies' => 3,
                    'available_copies' => 3,
                ],
                [
                    'title' => 'Pachinko',
                    'author' => 'Min Jin Lee',
                    'isbn' => '9781455563937',
                    'description' => 'A multigenerational family story spanning Korea, Japan, and the twentieth century.',
                    'published_year' => 2017,
                    'total_copies' => 2,
                    'available_copies' => 2,
                ],
                [
                    'title' => 'Kindred',
                    'author' => 'Octavia E. Butler',
                    'isbn' => '9780807083697',
                    'description' => 'A speculative novel about history, family, and survival.',
                    'published_year' => 1979,
                    'total_copies' => 4,
                    'available_copies' => 4,
                ],
                [
                    'title' => 'The Midnight Library',
                    'author' => 'Matt Haig',
                    'isbn' => '9780525559498',
                    'description' => 'A story about alternate lives and the choices that shape them.',
                    'published_year' => 2020,
                    'total_copies' => 2,
                    'available_copies' => 2,
                ],
            ] as $book
        ) {
            Book::query()->firstOrCreate(['isbn' => $book['isbn']], $book);
        }
    }
}
