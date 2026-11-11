<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SPEC 5.2. The private HR record is split from users (D-14): visibility is enforced per field.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 32)->nullable();             // E.164
            $table->string('gender', 16)->nullable();            // Gender enum
            $table->text('address')->nullable();
            $table->string('university')->nullable();
            $table->string('field_of_study')->nullable();
            $table->string('marital_status', 16)->nullable();    // MaritalStatus enum
            $table->string('source_of_hire')->nullable();        // free string (D-19)
            $table->boolean('has_health_insurance')->default(false);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();                // key => value
            $table->timestamps();
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32);                         // E.164
            $table->string('relationship');                      // free string: spouse, brother, ...
            $table->timestamps();
        });

        // Skills with a level (D-01: Skill and Area merged into technologies).
        Schema::create('technology_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technology_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->timestamps();

            $table->unique(['user_id', 'technology_id']);
        });

        Schema::getConnection()->statement(
            'ALTER TABLE technology_user ADD CONSTRAINT technology_user_level_check CHECK (level BETWEEN 1 AND 10)'
        );

        // Append-only history (BR-29): rows are never edited or deleted, only their status moves.
        Schema::create('salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->date('effective_date');
            $table->string('status', 16);                        // SalaryStatus enum [ASSUMED D-19]
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salaries');
        Schema::dropIfExists('technology_user');
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('employee_profiles');
    }
};
