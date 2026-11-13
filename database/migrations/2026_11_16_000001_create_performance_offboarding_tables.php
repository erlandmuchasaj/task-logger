<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SPEC 5.2 (performances, offboardings). `active_user_id` is a generated column [DERIVED]: it equals user_id
// while the offboarding is not cancelled, so a unique index allows at most one non-cancelled offboarding per user.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();                     // the employee reviewed
            $table->foreignId('created_by')->constrained('users');           // performed_by
            $table->unsignedTinyInteger('score');                            // 1 to 10
            $table->text('notes')->nullable();
            $table->date('review_date');                                     // [DERIVED]
            $table->timestamps();

            $table->index(['user_id', 'review_date']);
        });

        Schema::getConnection()->statement(
            'ALTER TABLE performances ADD CONSTRAINT performances_score_check CHECK (score BETWEEN 1 AND 10)'
        );

        Schema::create('offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->date('end_date');
            $table->boolean('equipment_returned')->default(false);
            $table->boolean('resignation_letter_signed')->default(false);
            $table->text('description')->nullable();
            $table->string('status', 16);                                    // OffboardingStatus enum [ASSUMED D-19]
            $table->timestamps();

            $table->unsignedBigInteger('active_user_id')->nullable()
                ->virtualAs("CASE WHEN status <> 'cancelled' THEN user_id END")->unique();
            $table->index(['status', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboardings');
        Schema::dropIfExists('performances');
    }
};
