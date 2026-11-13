<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SPEC 5.6 [OPTIONAL D-17]. Money is unsigned minor units plus a currency (BR-12). No soft deletes: only drafts are
// ever deleted (D-52), everything else is history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();                     // the employee the expense is for
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('project_id')->nullable()->constrained();
            $table->string('category', 16);                                  // ExpenseCategory enum
            $table->string('description');
            $table->string('number', 16)->unique();                          // EXP-000001, set from the row id (D-52)
            $table->string('receipt_path')->nullable();                      // private disk
            $table->text('notes')->nullable();
            $table->string('status', 16);                                    // ExpenseStatus enum
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        Schema::create('expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('amount_minor');                      // quantity x unit price, computed on the server
            $table->char('currency_code', 3);
            $table->date('date');
            $table->timestamps();
        });

        Schema::getConnection()->statement(
            'ALTER TABLE expense_lines ADD CONSTRAINT expense_lines_amount_check CHECK (amount_minor = quantity * unit_price_minor AND quantity > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expenses');
    }
};
