<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SPEC 5.2 operational columns. Salary and leave balance are NOT columns (D-03).
// country/department/employment_type/start_date are nullable at the database level so the
// first superadmin can be seeded; the CreateUser action (M2) requires them (W-01).
// D-67: user 1, the first superadmin the seeder creates, can be neither deleted nor soft-deleted. The database refuses it
// whatever the code does. On a server with binary logging, creating a trigger needs SUPER or log_bin_trust_function_creators=1.
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

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_protect_root_delete BEFORE DELETE ON users FOR EACH ROW
            BEGIN
                IF OLD.id = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User 1 is the root superadmin and cannot be deleted.';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_protect_root_soft_delete BEFORE UPDATE ON users FOR EACH ROW
            BEGIN
                IF OLD.id = 1 AND OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User 1 is the root superadmin and cannot be deleted.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS users_protect_root_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS users_protect_root_soft_delete');

        Schema::dropIfExists('users');
    }
};
