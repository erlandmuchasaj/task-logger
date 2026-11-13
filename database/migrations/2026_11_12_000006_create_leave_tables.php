<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SPEC 5.5. No columns beyond the spec; `idempotency_key` is a generated column [DERIVED] that makes the
// annual grant, carry-over and expiry entries unique per user, type and date (NFR-13, BR-18).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();                     // the employee
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('start_day_part', 8)->default('full');            // DayPart enum
            $table->string('end_day_part', 8)->default('full');
            $table->decimal('days_requested', 4, 1);                         // computed on the server (BR-16)
            $table->text('reason')->nullable();
            $table->string('status', 16);                                    // LeaveStatus enum
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();                         // required when rejected (BR-21)
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'start_date']);
            $table->index(['status', 'end_date']);
        });

        Schema::getConnection()->statement(
            'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_dates_check CHECK (end_date >= start_date)'
        );

        Schema::create('leave_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 16);                                     // ApprovalStage enum
            $table->foreignId('reviewer_id')->constrained('users');
            $table->string('decision', 16);                                  // ApprovalDecision enum
            $table->dateTime('decided_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'stage']);
        });

        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('leave_type_id')->constrained();
            $table->string('entry_type', 16);                                // LedgerEntryType enum
            $table->decimal('days', 4, 1);                                   // signed
            $table->date('effective_on');
            $table->date('expires_on')->nullable();
            $table->foreignId('leave_request_id')->nullable()->constrained();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->string('idempotency_key', 16)->nullable()
                ->virtualAs("CASE WHEN entry_type IN ('grant', 'carry_over', 'expiry') THEN entry_type END");

            $table->index(['user_id', 'leave_type_id']);
            $table->unique(['user_id', 'leave_type_id', 'effective_on', 'idempotency_key'], 'leave_ledger_idempotent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('leave_request_approvals');
        Schema::dropIfExists('leave_requests');
    }
};
