<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('code', 2)->unique();          // ISO 3166-1 alpha-2
            $table->string('prefix', 8);                // phone prefix, e.g. +355
            $table->string('timezone', 64);             // IANA, e.g. Europe/Tirane
            $table->char('currency_code', 3);           // ISO 4217
            $table->char('language', 2)->nullable();    // ISO 639-1 main language, informational (D-61)
            $table->json('working_days');               // ISO weekdays, 1 = Monday (SPEC 5.1 [DERIVED])
            $table->string('weekend_holiday_rule', 32)->default('stays'); // stays | next_working_day (D-62)
            $table->timestamps();
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('affects_balance')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('color', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('technologies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32);                 // PaymentPlanType enum
            $table->unsignedSmallInteger('days')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('country_leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->decimal('days_per_year', 4, 1);
            $table->date('effective_from');
            $table->string('proration', 16)->default('by_start_date'); // Proration enum
            $table->boolean('carry_over_enabled')->default(false);
            $table->decimal('carry_over_max_days', 4, 1)->nullable();
            $table->unsignedTinyInteger('carry_over_expires_after_months')->nullable();
            $table->timestamps();

            $table->unique(['country_id', 'leave_type_id', 'effective_from'], 'country_leave_policies_unique');
        });

        Schema::create('bank_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->boolean('repeats_yearly')->default(false);       // D-59: same day and month every year
            $table->unsignedSmallInteger('repeat_until_year')->nullable(); // D-59: last year it applies
            $table->timestamps();

            $table->index(['country_id', 'start_date']);
        });

        Schema::getConnection()->statement(
            'ALTER TABLE bank_holidays ADD CONSTRAINT bank_holidays_dates_check CHECK (end_date >= start_date)'
        );
        Schema::getConnection()->statement(
            'ALTER TABLE bank_holidays ADD CONSTRAINT bank_holidays_repeat_check CHECK (repeat_until_year IS NULL OR (repeats_yearly = 1 AND repeat_until_year >= YEAR(start_date)))'
        );
        Schema::getConnection()->statement(
            'ALTER TABLE bank_holidays ADD CONSTRAINT bank_holidays_repeat_span_check CHECK (repeats_yearly = 0 OR (DATEDIFF(end_date, start_date) < 365 AND NOT (MONTH(start_date) = 2 AND DAY(start_date) = 29) AND NOT (MONTH(end_date) = 2 AND DAY(end_date) = 29)))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_holidays');
        Schema::dropIfExists('country_leave_policies');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('payment_plans');
        Schema::dropIfExists('technologies');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('countries');
    }
};
