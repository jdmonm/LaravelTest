<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assistant_checkout_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('prepared_by_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('borrower_id')->constrained('users')->restrictOnDelete();
            $table->date('due_at')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('loan_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['prepared_by_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_checkout_drafts');
    }
};
