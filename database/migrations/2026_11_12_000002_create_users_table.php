<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SPEC 5.2 operational columns. Salary and leave balance are NOT columns (D-03).
// country/department/employment_type/start_date are nullable at the database level so the
// first superadmin can be seeded; the CreateUser action (M2) requires them (W-01).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('surname')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false); // D-53: set with a temporary password, cleared when the person sets their own
            $table->rememberToken();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('position')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employment_type', 32)->nullable();   // EmploymentType enum
            $table->date('start_date')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamp('banned_at')->nullable();          // cybercog/laravel-ban
            $table->text('app_authentication_secret')->nullable();          // TOTP secret, encrypted cast (ADR-0005)
            $table->text('app_authentication_recovery_codes')->nullable();  // encrypted:array cast
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
