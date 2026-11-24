<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Department;
use App\Domain\People\Support\OrgNode;
use App\Domain\People\Support\OrgTree;
use App\Filament\Pages\Hierarchy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** @param list<OrgNode> $nodes */
function orgNames(array $nodes): array
{
    return array_map(fn (OrgNode $node): string => $node->name, $nodes);
}

/** @param list<OrgNode> $nodes */
function orgNamesDeep(array $nodes): array
{
    return array_merge(...array_map(fn (OrgNode $node): array => [$node->name, ...orgNamesDeep($node->children)], $nodes));
}

it('D-79 builds the tree from the people with no manager down to their reports', function (): void {
    $ceo = User::factory()->create(['name' => 'Ceo', 'surname' => 'One', 'position' => 'CEO']);
    $lead = User::factory()->create(['name' => 'Lead', 'surname' => 'Two', 'position' => 'Lead', 'manager_id' => $ceo->id]);
    User::factory()->create(['name' => 'Dev', 'surname' => 'B', 'manager_id' => $lead->id]);
    User::factory()->create(['name' => 'Dev', 'surname' => 'A', 'manager_id' => $lead->id]);
    User::factory()->create(['name' => 'Ops', 'surname' => 'Three', 'manager_id' => $ceo->id]);

    $roots = OrgTree::roots();

    expect(orgNames($roots))->toBe(['Ceo One'])
        ->and(orgNames($roots[0]->children))->toBe(['Lead Two', 'Ops Three'])
        ->and(orgNames($roots[0]->children[0]->children))->toBe(['Dev A', 'Dev B'])
        ->and($roots[0]->position)->toBe('CEO')
        ->and($roots[0]->initials)->toBe('CO')
        ->and($roots[0]->descendants())->toBe(4);
})->group('D-79');

it('D-79 leaves out deactivated and anonymised people, and a person under one becomes a root', function (): void {
    $boss = User::factory()->create(['name' => 'Boss', 'surname' => 'X']);
    $left = User::factory()->create(['name' => 'Left', 'surname' => 'X', 'manager_id' => $boss->id, 'banned_at' => now()]);
    $anon = User::factory()->create(['name' => 'Anon', 'surname' => 'X', 'manager_id' => $boss->id, 'anonymized_at' => now()]);
    $removed = User::factory()->create(['name' => 'Removed', 'surname' => 'X', 'manager_id' => $boss->id]);
    $removed->delete();
    User::factory()->create(['name' => 'Orphan', 'surname' => 'X', 'manager_id' => $left->id]);
    User::factory()->create(['name' => 'Orphan2', 'surname' => 'X', 'manager_id' => $anon->id]);
    User::factory()->create(['name' => 'Orphan3', 'surname' => 'X', 'manager_id' => $removed->id]);
    User::factory()->create(['name' => 'Kept', 'surname' => 'X', 'manager_id' => $boss->id]);

    $roots = OrgTree::roots();

    expect(orgNames($roots))->toBe(['Boss X', 'Orphan X', 'Orphan2 X', 'Orphan3 X'])
        ->and(orgNames($roots[0]->children))->toBe(['Kept X'])
        ->and(orgNamesDeep($roots))->not->toContain('Left X', 'Anon X', 'Removed X');
})->group('D-79');

it('D-79 stops at the configured depth, which also ends a manager loop', function (): void {
    config(['task-logger.hierarchy_max_depth' => 2]);

    $a = User::factory()->create(['name' => 'A', 'surname' => 'X']);
    $b = User::factory()->create(['name' => 'B', 'surname' => 'X', 'manager_id' => $a->id]);
    $c = User::factory()->create(['name' => 'C', 'surname' => 'X', 'manager_id' => $b->id]);
    User::factory()->create(['name' => 'D', 'surname' => 'X', 'manager_id' => $c->id]);

    $roots = OrgTree::roots();

    expect($roots[0]->descendants())->toBe(2)
        ->and(orgNames($roots[0]->children[0]->children))->toBe(['C X']);

    // A and B manage each other: neither has a root above them, so the loop never starts, and nothing hangs.
    $a->forceFill(['manager_id' => $b->id])->save();

    expect(fn (): array => OrgTree::roots())->not->toThrow(Throwable::class);
})->group('D-79');

it('D-79 reads the whole tree in one query however many people there are', function (int $people): void {
    $manager = User::factory()->create();
    foreach (range(1, $people) as $i) {
        $manager = User::factory()->create(['manager_id' => $i % 3 === 0 ? $manager->id : null]);
    }

    DB::enableQueryLog();
    OrgTree::roots();

    expect(DB::getQueryLog())->toHaveCount(1);
})->with([3, 30])->group('D-79');

it('D-79 shows the avatar from the public disk when there is one and initials when not', function (): void {
    User::factory()->create(['name' => 'Pic', 'surname' => 'Ture', 'avatar_path' => 'avatars/pic.png']);
    User::factory()->create(['name' => 'No', 'surname' => 'Pic']);

    $roots = OrgTree::roots();

    expect($roots[1]->avatarUrl)->toContain('avatars/pic.png')
        ->and($roots[0]->avatarUrl)->toBeNull();
})->group('D-79');

it('D-79 every role may open the Hierarchy page and sees names and job titles, not roles or emails', function (RoleName $role): void {
    $viewer = userWithRole($role);
    $report = User::factory()->create(['name' => 'Visible', 'surname' => 'Person', 'position' => 'Tester', 'manager_id' => $viewer->id, 'email' => 'private.address@example.test']);

    $this->actingAs($viewer);

    Livewire::test(Hierarchy::class)
        ->assertOk()
        ->assertSee('Visible Person')
        ->assertSee('Tester')
        ->assertDontSee('private.address@example.test');

    $this->get('/app/hierarchy')->assertOk();
})->with(RoleName::cases())->group('D-79');

it('D-79 a person with no role is refused the Hierarchy page', function (): void {
    $this->actingAs(User::factory()->create()->fresh());

    $this->get('/app/hierarchy')->assertForbidden();
})->group('D-79');

it('D-89 orders siblings by department, then by name, with people who have no department last', function (): void {
    $boss = User::factory()->create(['name' => 'Boss', 'surname' => 'X']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $design = Department::factory()->create(['name' => 'Design']);
    User::factory()->create(['name' => 'Zed', 'surname' => 'X', 'manager_id' => $boss->id, 'department_id' => $design->id]);
    User::factory()->create(['name' => 'Abe', 'surname' => 'X', 'manager_id' => $boss->id]);
    User::factory()->create(['name' => 'Cy', 'surname' => 'X', 'manager_id' => $boss->id, 'department_id' => $sales->id]);
    User::factory()->create(['name' => 'Bo', 'surname' => 'X', 'manager_id' => $boss->id, 'department_id' => $design->id]);

    expect(orgNames(OrgTree::roots()[0]->children))->toBe(['Bo X', 'Zed X', 'Cy X', 'Abe X']);
})->group('D-89');

it("D-89 gives a card the colour of its department, the parent department's when it has none, and never an unsafe value", function (): void {
    $parent = Department::factory()->create(['color' => '#22c55e']);
    $child = Department::factory()->create(['parent_id' => $parent->id, 'color' => null]);
    $own = Department::factory()->create(['color' => '#abc']);
    $unsafe = Department::factory()->create(['color' => 'red;background:url(x)']);

    $boss = User::factory()->create(['name' => 'Boss', 'surname' => 'X']);
    foreach (['A' => $child, 'B' => $own, 'C' => $unsafe, 'D' => null] as $name => $department) {
        User::factory()->create(['name' => $name, 'surname' => 'X', 'manager_id' => $boss->id, 'department_id' => $department?->id]);
    }

    $cards = collect(OrgTree::roots()[0]->children)->keyBy('name');

    expect($cards['A X']->color)->toBe('#22c55e')
        ->and($cards['A X']->department)->toBe($child->name)
        ->and($cards['B X']->color)->toBe('#abc')
        ->and($cards['C X']->color)->toBeNull()
        ->and($cards['D X']->color)->toBeNull()
        ->and($cards['D X']->department)->toBeNull();
})->group('D-89');

it('D-89 lists each department once in the legend and opens the chart two levels below the top person', function (): void {
    config(['task-logger.hierarchy_open_levels' => 2]);

    $design = Department::factory()->create(['name' => 'Design', 'color' => '#ef4444']);
    $top = User::factory()->create(['name' => 'Top', 'surname' => 'X', 'department_id' => $design->id]);
    $mid = User::factory()->create(['name' => 'Mid', 'surname' => 'X', 'manager_id' => $top->id, 'department_id' => $design->id]);
    $low = User::factory()->create(['name' => 'Low', 'surname' => 'X', 'manager_id' => $mid->id]);
    $leaf = User::factory()->create(['name' => 'Leaf', 'surname' => 'X', 'manager_id' => $low->id]);
    User::factory()->create(['name' => 'Tip', 'surname' => 'X', 'manager_id' => $leaf->id]);

    $roots = OrgTree::roots();

    expect(OrgTree::legend($roots))->toBe([['name' => 'Design', 'color' => '#ef4444']])
        ->and(OrgTree::initiallyOpen($roots))->toBe([$top->id => true, $mid->id => true, $low->id => false, $leaf->id => false]);
})->group('D-89');

it('D-89 the chart shows departments, the legend and a profile link for each person, and a count only where people sit below', function (): void {
    $design = Department::factory()->create(['name' => 'Design', 'color' => '#ef4444']);
    $viewer = userWithRole(RoleName::Employee);
    $viewer->forceFill(['department_id' => $design->id])->save();
    $report = User::factory()->create(['name' => 'Rep', 'surname' => 'Ort', 'manager_id' => $viewer->id]);

    $this->actingAs($viewer);

    Livewire::test(Hierarchy::class)
        ->assertSee('Design')
        ->assertSee('background-color: #ef4444', false)
        ->assertSee('border-color: #ef4444', false)
        ->assertSee('/hierarchy/'.$viewer->id, false)
        ->assertSee('/hierarchy/'.$report->id, false)
        ->assertSee('Show or hide the 1 person below', false)
        ->assertDontSee('below Rep Ort');
})->group('D-89');
