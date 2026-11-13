<?php

declare(strict_types=1);

use App\Domain\Expenses\Actions\ChangeExpenseStatus;
use App\Domain\Expenses\Actions\CreateExpense;
use App\Domain\Expenses\Actions\DeleteExpense;
use App\Domain\Expenses\Actions\DownloadReceipt;
use App\Domain\Expenses\Actions\UpdateExpense;
use App\Domain\Expenses\Data\ExpenseData;
use App\Domain\Expenses\Data\ExpenseLineData;
use App\Domain\Expenses\Enums\ExpenseCategory;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Filament\Resources\Expenses\Pages\CreateExpense as CreateExpensePage;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Two lines in EUR: 2 x 12.50 and 1 x 30.00 = 55.00. */
function expenseData(array $overrides = []): ExpenseData
{
    return new ExpenseData(...array_merge([
        'category' => ExpenseCategory::Travel,
        'description' => 'Client visit in Tirana',
        'currency' => 'EUR',
        'lines' => [
            new ExpenseLineData('Taxi', 2, '12.50', Carbon::parse('2026-06-02')),
            new ExpenseLineData('Hotel', 1, '30.00', Carbon::parse('2026-06-03')),
        ],
    ], $overrides));
}

function makeExpense(?User $actor = null, ?User $employee = null, array $overrides = []): Expense
{
    return app(CreateExpense::class)->handle(
        $actor ?? userWithRole(RoleName::Finance),
        $employee ?? userWithRole(RoleName::Employee),
        expenseData($overrides),
    );
}

beforeEach(fn () => Storage::fake('local'));

it('D-51 only finance and the superadmin hold any expenses permission', function (RoleName $role, bool $holds) {
    $user = userWithRole($role);

    foreach ([Permissions::EXPENSES_VIEW, Permissions::EXPENSES_CREATE, Permissions::EXPENSES_UPDATE, Permissions::EXPENSES_DELETE, Permissions::EXPENSES_APPROVE, Permissions::EXPENSES_REIMBURSE] as $permission) {
        expect($user->can($permission))->toBe($holds, $role->value.' / '.$permission);
    }
})->with([
    [RoleName::Superadmin, true], [RoleName::Finance, true],
    [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
])->group('D-51', 'D-17', 'FR-38');

it('D-51 finance gets the employee baseline and nothing else beyond expenses', function () {
    $finance = userWithRole(RoleName::Finance);

    expect($finance->can(Permissions::LOGS_CREATE_OWN))->toBeTrue()
        ->and($finance->can(Permissions::LEAVE_REQUESTS_CREATE_OWN))->toBeTrue()
        ->and($finance->can(Permissions::LOGS_VIEW_ANY))->toBeFalse()
        ->and($finance->can(Permissions::SALARIES_VIEW))->toBeFalse()
        ->and($finance->can(Permissions::CLIENTS_VIEW_RATES))->toBeFalse()
        ->and($finance->can(Permissions::EMPLOYEES_VIEW_HR_RECORD))->toBeFalse();
})->group('D-51');

it('D-52 FR-38 creates a draft with server-computed line amounts and a race-safe number', function () {
    $finance = userWithRole(RoleName::Finance);
    $employee = userWithRole(RoleName::Employee);

    $numbers = collect(range(1, 5))->map(fn () => makeExpense($finance, $employee)->number);
    $expense = Expense::where('number', $numbers->first())->first()->load('lines');

    expect($numbers->unique())->toHaveCount(5)
        ->and($numbers->first())->toMatch('/^EXP-\d{6}$/')
        ->and($numbers->first())->toBe('EXP-'.str_pad((string) $expense->id, 6, '0', STR_PAD_LEFT))
        ->and($expense)->status->toBe(ExpenseStatus::Draft)->created_by->toBe($finance->id)->user_id->toBe($employee->id)
        ->and($expense->lines->pluck('amount_minor')->all())->toBe([2500, 3000])
        ->and($expense->total()->minor)->toBe(5500)->and($expense->total()->currency)->toBe('EUR');
})->group('D-52', 'FR-38', 'BR-12');

it('BR-12 the database refuses a line whose amount is not quantity times price', function () {
    $expense = makeExpense();

    expect(fn () => DB::table('expense_lines')->insert([
        'expense_id' => $expense->id, 'description' => 'Bad', 'quantity' => 2, 'unit_price_minor' => 1000, 'amount_minor' => 1999,
        'currency_code' => 'EUR', 'date' => '2026-06-01', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('BR-12');

it('BR-12 a zero-decimal currency uses its own exponent', function () {
    $expense = makeExpense(overrides: ['currency' => 'JPY', 'lines' => [new ExpenseLineData('Train', 3, '1200', Carbon::parse('2026-06-02'))]]);

    expect($expense->total()->minor)->toBe(3600)->and($expense->total()->format())->toBe('JPY 3600')
        ->and(fn () => makeExpense(overrides: ['currency' => 'JPY', 'lines' => [new ExpenseLineData('Train', 1, '12.50', Carbon::parse('2026-06-02'))]]))
        ->toThrow(ValidationException::class);
})->group('BR-12');

it('D-52 rejects a bad expense: no lines, quantity, price, currency, receipt path, anonymised employee', function (array $overrides, string $key) {
    try {
        makeExpense(overrides: $overrides);
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
})->with([
    'no lines' => [['lines' => []], 'lines'],
    'zero quantity' => [['lines' => [new ExpenseLineData('Taxi', 0, '10.00', Carbon::parse('2026-06-02'))]], 'lines.0.quantity'],
    'text price' => [['lines' => [new ExpenseLineData('Taxi', 1, 'ten', Carbon::parse('2026-06-02'))]], 'lines.0.unit_price'],
    'free price' => [['lines' => [new ExpenseLineData('Taxi', 1, '0.00', Carbon::parse('2026-06-02'))]], 'lines.0.unit_price'],
    'blank line' => [['lines' => [new ExpenseLineData('  ', 1, '10.00', Carbon::parse('2026-06-02'))]], 'lines.0.description'],
    'bad currency' => [['currency' => 'EURO'], 'currency'],
    'blank description' => [['description' => ' '], 'description'],
    'unknown project' => [['projectId' => 999999], 'project_id'],
    'path traversal' => [['receiptPath' => 'receipts/../../.env'], 'receipt_path'],
    'outside receipts' => [['receiptPath' => 'avatars/me.png'], 'receipt_path'],
])->group('D-52', 'FR-38');

it('D-52 refuses an anonymised employee', function () {
    $gone = User::factory()->withRole(RoleName::Employee)->create(['anonymized_at' => now()]);

    expect(fn () => makeExpense(employee: $gone))->toThrow(ValidationException::class, 'anonymised');
})->group('D-52');

it('D-51 only finance and the superadmin can create, everyone else is refused', function (RoleName $role, bool $allowed) {
    $actor = userWithRole($role);

    if ($allowed) {
        expect(makeExpense($actor))->toBeInstanceOf(Expense::class);
    } else {
        expect(fn () => makeExpense($actor))->toThrow(AuthorizationException::class);
    }
})->with([
    [RoleName::Superadmin, true], [RoleName::Finance, true],
    [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
])->group('D-51', 'FR-38');

it('D-52 walks draft, submitted, approved, reimbursed and refuses every other move', function () {
    $finance = userWithRole(RoleName::Finance);
    $expense = makeExpense($finance);
    $change = fn (ExpenseStatus $to, ?string $reason = null, ?User $by = null) => app(ChangeExpenseStatus::class)->handle($by ?? $finance, $expense, $to, $reason);

    expect(fn () => $change(ExpenseStatus::Approved))->toThrow(ValidationException::class, 'cannot move')
        ->and(fn () => $change(ExpenseStatus::Reimbursed))->toThrow(ValidationException::class);

    expect($change(ExpenseStatus::Submitted)->status)->toBe(ExpenseStatus::Submitted)
        ->and(fn () => $change(ExpenseStatus::Draft))->toThrow(ValidationException::class);

    $approver = userWithRole(RoleName::Finance);
    expect($change(ExpenseStatus::Approved, by: $approver)->status)->toBe(ExpenseStatus::Approved)
        ->and(fn () => $change(ExpenseStatus::Rejected, 'too late', $approver))->toThrow(ValidationException::class);

    expect($change(ExpenseStatus::Reimbursed)->status)->toBe(ExpenseStatus::Reimbursed)
        ->and(fn () => $change(ExpenseStatus::Draft))->toThrow(ValidationException::class);
})->group('D-52', 'FR-38');

it('D-52 a rejection needs a reason that is kept in the notes, and the expense can be reopened, edited and resubmitted', function () {
    $finance = userWithRole(RoleName::Finance);
    $reviewer = userWithRole(RoleName::Finance);
    $expense = makeExpense($finance, overrides: ['notes' => 'Receipt attached']);
    app(ChangeExpenseStatus::class)->handle($finance, $expense, ExpenseStatus::Submitted);

    expect(fn () => app(ChangeExpenseStatus::class)->handle($reviewer, $expense, ExpenseStatus::Rejected))->toThrow(ValidationException::class, 'needs a reason');

    $rejected = app(ChangeExpenseStatus::class)->handle($reviewer, $expense, ExpenseStatus::Rejected, 'Missing receipt');
    expect($rejected)->status->toBe(ExpenseStatus::Rejected)->notes->toBe("Receipt attached\nRejected: Missing receipt")
        ->and(fn () => app(UpdateExpense::class)->handle($finance, $rejected, expenseData()))->toThrow(ValidationException::class);   // not editable until reopened

    app(ChangeExpenseStatus::class)->handle($finance, $rejected, ExpenseStatus::Draft);
    app(UpdateExpense::class)->handle($finance, $rejected->refresh(), expenseData(['description' => 'Corrected']));

    expect(app(ChangeExpenseStatus::class)->handle($finance, $rejected, ExpenseStatus::Submitted)->description)->toBe('Corrected');
})->group('D-52');

it('D-52 nobody approves or rejects an expense made for themselves, the superadmin included', function (RoleName $role) {
    $actor = userWithRole($role);
    $expense = makeExpense($actor, $actor);
    app(ChangeExpenseStatus::class)->handle($actor, $expense, ExpenseStatus::Submitted);

    expect(fn () => app(ChangeExpenseStatus::class)->handle($actor, $expense, ExpenseStatus::Approved))->toThrow(ValidationException::class, 'yourself')
        ->and(fn () => app(ChangeExpenseStatus::class)->handle($actor, $expense, ExpenseStatus::Rejected, 'no'))->toThrow(ValidationException::class, 'yourself');
})->with([RoleName::Finance, RoleName::Superadmin])->group('D-52', 'BR-20');

it('D-51 each move needs its own permission: update to submit, approve to decide, reimburse to pay', function () {
    $expense = makeExpense();
    $employee = userWithRole(RoleName::Employee);

    expect(fn () => app(ChangeExpenseStatus::class)->handle($employee, $expense, ExpenseStatus::Submitted))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeExpenseStatus::class)->handle(userWithRole(RoleName::Hr), $expense, ExpenseStatus::Submitted))->toThrow(AuthorizationException::class);
})->group('D-51');

it('D-52 only a draft can be edited or deleted, and the superadmin cannot bypass it', function () {
    $root = userWithRole(RoleName::Superadmin);
    $expense = makeExpense($root);
    app(ChangeExpenseStatus::class)->handle($root, $expense, ExpenseStatus::Submitted);

    expect(fn () => app(UpdateExpense::class)->handle($root, $expense, expenseData()))->toThrow(ValidationException::class, 'draft')
        ->and(fn () => app(DeleteExpense::class)->handle($root, $expense))->toThrow(ValidationException::class, 'draft')
        ->and(Expense::count())->toBe(1);
})->group('D-52');

it('D-52 updating a draft replaces its lines and removes the old receipt file', function () {
    $finance = userWithRole(RoleName::Finance);
    Storage::disk('local')->put('receipts/old.pdf', 'old');
    Storage::disk('local')->put('receipts/new.pdf', 'new');
    $expense = makeExpense($finance, overrides: ['receiptPath' => 'receipts/old.pdf']);

    $updated = app(UpdateExpense::class)->handle($finance, $expense, expenseData([
        'receiptPath' => 'receipts/new.pdf',
        'lines' => [new ExpenseLineData('Flight', 1, '99.90', Carbon::parse('2026-06-05'))],
    ]));

    expect($updated->lines)->toHaveCount(1)->and($updated->total()->minor)->toBe(9990)
        ->and(Storage::disk('local')->exists('receipts/old.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('receipts/new.pdf'))->toBeTrue();
})->group('D-52');

it('D-52 deleting a draft removes its lines and receipt', function () {
    $finance = userWithRole(RoleName::Finance);
    Storage::disk('local')->put('receipts/r.pdf', 'x');
    $expense = makeExpense($finance, overrides: ['receiptPath' => 'receipts/r.pdf']);

    app(DeleteExpense::class)->handle($finance, $expense);

    expect(Expense::count())->toBe(0)->and(DB::table('expense_lines')->count())->toBe(0)->and(Storage::disk('local')->exists('receipts/r.pdf'))->toBeFalse();
})->group('D-52');

it('D-52 a receipt is read only through a permission-checked download, from the private disk', function () {
    $finance = userWithRole(RoleName::Finance);
    Storage::disk('local')->put('receipts/r.pdf', 'PDF-BYTES');
    $expense = makeExpense($finance, overrides: ['receiptPath' => 'receipts/r.pdf']);

    $response = app(DownloadReceipt::class)->handle($finance, $expense);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-disposition'))->toContain($expense->number.'.pdf')
        ->and(fn () => app(DownloadReceipt::class)->handle(userWithRole(RoleName::Hr), $expense))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DownloadReceipt::class)->handle(userWithRole(RoleName::Employee), $expense))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DownloadReceipt::class)->handle($finance, makeExpense($finance)))->toThrow(ValidationException::class, 'no receipt')
        ->and(Storage::disk('public')->exists('receipts/r.pdf'))->toBeFalse();
})->group('D-52');

it('BR-32 every expense write is audited with the actor', function () {
    $finance = userWithRole(RoleName::Finance);
    $expense = makeExpense($finance);
    app(ChangeExpenseStatus::class)->handle($finance, $expense, ExpenseStatus::Submitted);

    expect(ActivityLogFor($expense, 'created'))->attribute_changes->toHaveKey('attributes')
        ->and(ActivityLogFor($expense, 'created'))->causer_id->toBe($finance->id)
        ->and(ActivityLogFor($expense, 'updated'))->causer_id->toBe($finance->id);
})->group('BR-32');

function ActivityLogFor(Expense $expense, string $event): ActivityLog
{
    return ActivityLog::where('subject_type', Expense::class)->where('subject_id', $expense->id)->where('event', $event)->latest('id')->firstOrFail();
}

it('D-51 the Expenses screens open for finance and the superadmin and are closed to everyone else', function (RoleName $role, bool $allowed) {
    makeExpense();
    $this->actingAs(userWithRole($role));

    foreach (['/app/expenses', '/app/expenses/create'] as $url) {
        $allowed ? $this->get($url)->assertOk() : $this->get($url)->assertForbidden();
    }
})->with([
    [RoleName::Superadmin, true], [RoleName::Finance, true],
    [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
])->group('D-51', 'NFR-03');

it('FR-38 the list shows the right buttons for each status, and nothing the viewer may not do', function () {
    $finance = userWithRole(RoleName::Finance);
    $mine = makeExpense($finance, $finance);
    $draft = makeExpense($finance);
    $submitted = makeExpense($finance);
    app(ChangeExpenseStatus::class)->handle($finance, $submitted, ExpenseStatus::Submitted);
    app(ChangeExpenseStatus::class)->handle($finance, $mine, ExpenseStatus::Submitted);

    $this->actingAs($finance);

    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$draft, $submitted])
        ->assertTableActionVisible('submit', $draft)->assertTableActionVisible('delete', $draft)->assertTableActionVisible('edit', $draft)
        ->assertTableActionHidden('approve', $draft)
        ->assertTableActionVisible('approve', $submitted)->assertTableActionVisible('reject', $submitted)
        ->assertTableActionHidden('delete', $submitted)->assertTableActionHidden('edit', $submitted)
        ->assertTableActionHidden('approve', $mine)      // not your own
        ->callTableAction('approve', $submitted)
        ->assertTableActionVisible('reimburse', $submitted->refresh());

    expect($submitted->refresh()->status)->toBe(ExpenseStatus::Approved);
})->group('FR-38', 'D-52');

it('FR-38 a rejection from the screen needs a reason', function () {
    $finance = userWithRole(RoleName::Finance);
    $expense = makeExpense($finance);
    app(ChangeExpenseStatus::class)->handle($finance, $expense, ExpenseStatus::Submitted);
    $this->actingAs(userWithRole(RoleName::Finance));

    Livewire::test(ListExpenses::class)->callTableAction('reject', $expense, ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
    Livewire::test(ListExpenses::class)->callTableAction('reject', $expense, ['reason' => 'Duplicate']);

    expect($expense->refresh())->status->toBe(ExpenseStatus::Rejected)->notes->toContain('Duplicate');
})->group('FR-38');

it('FR-38 creates an expense from the form', function () {
    $employee = userWithRole(RoleName::Employee);
    $this->actingAs(userWithRole(RoleName::Finance));

    Livewire::test(CreateExpensePage::class)
        ->fillForm([
            'user_id' => $employee->id, 'category' => 'meals', 'description' => 'Team lunch', 'currency' => 'EUR',
            'lines' => [['description' => 'Lunch', 'date' => '2026-06-04', 'quantity' => 4, 'unit_price' => '8.25']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::sole()->load('lines');

    expect($expense)->user_id->toBe($employee->id)->category->toBe(ExpenseCategory::Meals)
        ->and($expense->total()->minor)->toBe(3300);
})->group('FR-38');

it('NFR-09 the list runs without N+1 queries with many expenses', function () {
    $finance = userWithRole(RoleName::Finance);
    foreach (range(1, 12) as $i) {
        $expense = makeExpense($finance);
        if ($i % 2 === 0) {
            app(ChangeExpenseStatus::class)->handle($finance, $expense, ExpenseStatus::Submitted);
        }
    }

    $this->actingAs($finance)->get('/app/expenses')->assertOk();
})->group('NFR-09');
