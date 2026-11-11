<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SPEC 5.4 (logs, timesheet_locks) plus the export bookkeeping table (BR-33).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();                    // who logged
            $table->date('date');                                           // the work date, in the user's country timezone (BR-34)
            $table->foreignId('project_id')->constrained();
            $table->foreignId('task_id')->nullable()->constrained();        // null = direct log (BR-02)
            $table->foreignId('technology_id')->constrained();
            $table->unsignedSmallInteger('duration_minutes');               // BR-04
            $table->string('log_type', 32);                                 // LogType enum (BR-05)
            $table->text('description')->nullable();
            // Rate snapshot (BR-06): the client's rate on the log date. Later rate changes never alter it.
            $table->unsignedBigInteger('rate_minor');
            $table->char('rate_currency', 3);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['project_id', 'date']);
            $table->index('task_id');

            // BR-02: the technology of a log belongs to its project.
            $table->foreign(['project_id', 'technology_id'])
                ->references(['project_id', 'technology_id'])->on('project_technology');
        });

        Schema::getConnection()->statement(
            'ALTER TABLE logs ADD CONSTRAINT logs_duration_check CHECK (duration_minutes > 0 AND duration_minutes <= 1440)'
        );

        Schema::create('timesheet_locks', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();                                // first day of the locked month
            $table->foreignId('locked_by')->constrained('users');
            $table->dateTime('locked_at');
            $table->foreignId('unlocked_by')->nullable()->constrained('users');
            $table->dateTime('unlocked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 16);                                     // ExportType enum
            $table->string('status', 16);                                   // ExportStatus enum
            $table->json('filters')->nullable();
            $table->json('request_context')->nullable();                    // IP, user agent, impersonator for the audit trail (D-38)
            $table->string('file_path')->nullable();                        // on the private disk
            $table->unsignedInteger('rows')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
        Schema::dropIfExists('timesheet_locks');
        Schema::dropIfExists('logs');
    }
};
