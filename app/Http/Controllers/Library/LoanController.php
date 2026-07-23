<?php

namespace App\Http\Controllers\Library;

use App\Actions\Library\CheckInLoan;
use App\Actions\Library\CheckOutBook;
use App\Enums\LibraryPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\StoreLoanRequest;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoanController extends Controller
{
    /**
     * Show loans, limiting members to their own circulation history.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewAllLoans = $user->can(LibraryPermission::ViewLoans->value);
        $canManageLoans = $user->can(LibraryPermission::ManageLoans->value);
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        $loans = Loan::query()
            ->with([
                'book:id,title,author,isbn',
                'borrower:id,name,email',
            ])
            ->when(! $canViewAllLoans, static function (Builder $query) use ($user): void {
                $query->where('borrower_id', $user->id);
            })
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('book', static function (Builder $query) use ($search): void {
                            $query
                                ->where('title', 'like', "%{$search}%")
                                ->orWhere('author', 'like', "%{$search}%")
                                ->orWhere('isbn', 'like', "%{$search}%");
                        })
                        ->orWhereHas('borrower', static function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($status === 'active', static function (Builder $query): void {
                $query->whereNull('returned_at')->where(function (Builder $query): void {
                    $query->whereNull('due_at')->orWhere('due_at', '>=', now());
                });
            })
            ->when($status === 'overdue', static function (Builder $query): void {
                $query->whereNull('returned_at')->where('due_at', '<', now());
            })
            ->when($status === 'returned', static function (Builder $query): void {
                $query->whereNotNull('returned_at');
            })
            ->latest('checked_out_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('library/LoansIndex', [
            'loans' => $loans,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'canManageLoans' => $canManageLoans,
            'availableBooks' => $canManageLoans
                ? Book::query()
                ->where('available_copies', '>', 0)
                ->orderBy('title')
                ->get(['id', 'title', 'author', 'available_copies'])
                : [],
            'members' => $canManageLoans
                ? User::query()->orderBy('name')->get(['id', 'name', 'email'])
                : [],
        ]);
    }

    /**
     * Check out an available book to a member.
     */
    public function store(StoreLoanRequest $request, CheckOutBook $checkOutBook): RedirectResponse
    {
        $request->validated();

        $book = Book::query()->findOrFail($request->integer('book_id'));
        $borrower = User::query()->findOrFail($request->integer('borrower_id'));
        $dueAt = $request->date('due_at');

        $checkOutBook->handle($book, $borrower, $dueAt);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Book checked out.']);

        return to_route('loans.index');
    }

    /**
     * Return a book to inventory.
     */
    public function checkIn(Loan $loan, CheckInLoan $checkInLoan): RedirectResponse
    {
        $checkInLoan->handle($loan);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Book checked in.']);

        return to_route('loans.index');
    }
}
