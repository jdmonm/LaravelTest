<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\Library\StoreBookRequest;
use App\Http\Requests\Library\UpdateBookRequest;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookController extends Controller
{
    /**
     * Display the searchable library catalogue.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        return Inertia::render('library/BooksIndex', [
            'books' => Book::query()
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $query->where(function (Builder $query) use ($search): void {
                        $query
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('author', 'like', "%{$search}%")
                            ->orWhere('isbn', 'like', "%{$search}%");
                    });
                })
                ->orderBy('title')
                ->paginate(12)
                ->withQueryString(),
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    /**
     * Add a book to the catalogue.
     */
    public function store(StoreBookRequest $request): RedirectResponse
    {
        $attributes = $request->validated();
        $attributes['available_copies'] = $attributes['total_copies'];

        Book::query()->create($attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Book added to the catalogue.']);

        return to_route('books.index');
    }

    /**
     * Update a catalogue record while preserving active inventory.
     */
    public function update(UpdateBookRequest $request, Book $book): RedirectResponse
    {
        $attributes = $request->validated();
        $activeLoanCount = $book->activeLoans()->count();

        if ($attributes['total_copies'] < $activeLoanCount) {
            throw ValidationException::withMessages([
                'total_copies' => "Cannot set copies below the {$activeLoanCount} active loan(s).",
            ]);
        }

        $attributes['available_copies'] = $attributes['total_copies'] - $activeLoanCount;
        $book->update($attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Book updated.']);

        return to_route('books.index');
    }

    /**
     * Remove a book that has no loan history.
     */
    public function destroy(Book $book): RedirectResponse
    {
        if ($book->loans()->exists()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Books with loan history cannot be deleted.',
            ]);

            return to_route('books.index');
        }

        $book->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Book deleted.']);

        return to_route('books.index');
    }
}
