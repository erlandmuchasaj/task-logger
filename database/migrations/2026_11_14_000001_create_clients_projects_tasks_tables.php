<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SPEC 5.3 and 5.4 (tasks). Money is integer minor units plus an ISO currency (BR-12).
// Priority lives on the customer only (D-02): projects have no priority column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name');                                  // contact person
            $table->string('surname')->nullable();
            $table->string('email');
            $table->foreignId('country_id')->constrained();
            $table->string('phone', 32)->nullable();                 // E.164
            $table->text('address')->nullable();
            $table->string('company_name')->nullable();
            $table->string('priority', 16);                          // Priority enum
            $table->foreignId('payment_plan_id')->nullable()->constrained();
            $table->char('currency_code', 3);                        // defaults to the country's (D-18)
            $table->string('avatar_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_technology_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('technology_id')->constrained();
            $table->unsignedBigInteger('rate_minor');                // per hour, in the customer's currency
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['customer_id', 'technology_id', 'effective_from'], 'client_technology_rates_unique');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('customer_id')->constrained();
            $table->string('project_type', 32);                      // ProjectType enum
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('manager_id')->constrained('users');   // must hold the admin role (BR-30)
            $table->date('start_date');
            $table->date('end_date')->nullable();                    // the deadline
            $table->boolean('can_log_directly_to_project')->default(false);
            $table->string('status', 16)->default('planning');       // ProjectStatus enum
            $table->string('color', 32)->nullable();
            // Computed snapshots (D-04): recomputed by ComputeProjectBudget, never typed in.
            $table->decimal('estimated_hours', 10, 2)->default(0);
            $table->unsignedBigInteger('budget_minor')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
        });

        Schema::getConnection()->statement(
            'ALTER TABLE projects ADD CONSTRAINT projects_dates_check CHECK (end_date IS NULL OR end_date >= start_date)'
        );

        Schema::create('project_technology', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technology_id')->constrained();
            $table->decimal('estimated_hours', 8, 2);
            $table->timestamps();

            $table->unique(['project_id', 'technology_id']);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('fixed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->string('name');
            $table->date('date');                                    // first day of the month it applies to
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);                        // must equal the customer's (BR-14)
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('technology_id')->constrained();
            $table->string('status', 16);                            // TaskStatus enum
            $table->string('priority', 16);                          // Priority enum
            $table->decimal('estimated_hours', 8, 2)->default(0);
            $table->foreignId('assignee_id')->nullable()->constrained('users');  // one assignee (BR-09)
            $table->foreignId('parent_id')->nullable()->constrained('tasks');
            $table->boolean('force_to_log')->default(false);
            $table->date('due_date')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['assignee_id', 'status']);
            $table->index(['project_id', 'status']);

            // BR-10: a task's technology must belong to the task's project.
            $table->foreign(['project_id', 'technology_id'])
                ->references(['project_id', 'technology_id'])->on('project_technology');
        });

        Schema::create('label_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['label_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_task');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('fixed_costs');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('project_technology');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('client_technology_rates');
        Schema::dropIfExists('customers');
    }
};
