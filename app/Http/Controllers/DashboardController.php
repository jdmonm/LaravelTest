<?php

namespace App\Http\Controllers;

use App\Enums\LibraryPermission;
use App\Models\Book;
use App\Models\Loan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display a library overview tailored to the user's access level.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $activeLoans = Loan::query()->whereNull('returned_at');

        if (! $user->can(LibraryPermission::ViewLoans->value)) {
            $activeLoans->where('borrower_id', $user->id);
        }

        return Inertia::render('Dashboard', [
            'metrics' => [
                'books' => Book::query()->count(),
                'availableCopies' => Book::query()->sum('available_copies'),
                'activeLoans' => (clone $activeLoans)->count(),
                'overdueLoans' => (clone $activeLoans)
                    ->where('due_at', '<', now())
                    ->count(),
            ],
            'recentLoans' => $activeLoans
                ->with('book:id,title,author')
                ->latest('checked_out_at')
                ->limit(5)
                ->get()
                ->map(static fn (Loan $loan): array => [
                    'id' => $loan->id,
                    'book' => [
                        'id' => $loan->book->id,
                        'title' => $loan->book->title,
                        'author' => $loan->book->author,
                    ],
                    'due_at' => $loan->due_at?->toDateString(),
                    'is_overdue' => $loan->isOverdue(),
                ]),
        ]);
    }
}
