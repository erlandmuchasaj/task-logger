<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;

/**
 * D-79: the reporting tree of everyone who is active, from one recursive query (MySQL 8 common table expression),
 * so the number of queries does not grow with the number of people. A root is an active person with no manager or
 * whose manager is deactivated or anonymised, so nobody drops off the page when a manager leaves. The walk stops
 * `task-logger.hierarchy_max_depth` levels down, which also bounds it should a manager cycle ever exist. D-89: each
 * person also carries their department and its colour (the parent department's when it has none), and siblings come
 * in department order, then name order, people with no department last.
 */
final class OrgTree
{
    private const string ACTIVE = 'deleted_at IS NULL AND banned_at IS NULL AND anonymized_at IS NULL';

    /** @return list<OrgNode> the roots, each with its reports below it, people sorted by department, then name */
    public static function roots(): array
    {
        $active = self::ACTIVE;

        $rows = DB::select(
            "WITH RECURSIVE org AS (
                SELECT u.id, u.manager_id, u.department_id, u.name, u.surname, u.position, u.avatar_path, 0 AS depth
                FROM users u
                WHERE u.{$active}
                  AND (u.manager_id IS NULL OR NOT EXISTS (
                        SELECT 1 FROM users m WHERE m.id = u.manager_id AND m.deleted_at IS NULL AND m.banned_at IS NULL AND m.anonymized_at IS NULL))
                UNION ALL
                SELECT c.id, c.manager_id, c.department_id, c.name, c.surname, c.position, c.avatar_path, org.depth + 1
                FROM users c
                JOIN org ON c.manager_id = org.id
                WHERE c.{$active} AND org.depth < ?
            )
            SELECT org.id, org.manager_id, org.name, org.surname, org.position, org.avatar_path, org.depth,
                   d.name AS department_name, COALESCE(NULLIF(d.color, ''), pd.color) AS department_color
            FROM org
            LEFT JOIN departments d ON d.id = org.department_id
            LEFT JOIN departments pd ON pd.id = d.parent_id
            ORDER BY org.depth, (d.name IS NULL), d.name, org.name, org.surname, org.id",
            [max(0, (int) config('task-logger.hierarchy_max_depth'))],
        );

        $byManager = [];
        $roots = [];

        foreach ($rows as $row) {
            if ((int) $row->depth === 0) {
                $roots[] = $row;
            } else {
                $byManager[(int) $row->manager_id][] = $row;
            }
        }

        return array_map(fn (stdClass $row): OrgNode => self::node($row, $byManager), $roots);
    }

    /** D-89: a department colour is free text in the database; only `#rgb` or `#rrggbb` may reach an inline style. */
    public static function safeColor(?string $color): ?string
    {
        return $color !== null && preg_match('/^#(?:[0-9a-f]{3}){1,2}$/i', $color) === 1 ? $color : null;
    }

    /**
     * D-89: the departments shown on the chart, once each, in name order, for the legend.
     *
     * @param  list<OrgNode>  $roots
     * @return list<array{name: string, color: ?string}>
     */
    public static function legend(array $roots): array
    {
        $departments = [];

        $walk = function (array $nodes) use (&$walk, &$departments): void {
            foreach ($nodes as $node) {
                if ($node->department !== null) {
                    $departments[$node->department] ??= $node->color;
                }

                $walk($node->children);
            }
        };
        $walk($roots);

        ksort($departments);

        return array_map(
            fn (string $name, ?string $color): array => ['name' => $name, 'color' => $color],
            array_keys($departments),
            array_values($departments),
        );
    }

    /**
     * D-89: which people start expanded: every person with reports, true while fewer than
     * `task-logger.hierarchy_open_levels` levels sit above them (the top person is level 0).
     *
     * @param  list<OrgNode>  $roots
     * @return array<int, bool>
     */
    public static function initiallyOpen(array $roots): array
    {
        $levels = max(0, (int) config('task-logger.hierarchy_open_levels'));
        $open = [];

        $walk = function (array $nodes) use (&$walk, &$open, $levels): void {
            foreach ($nodes as $node) {
                if ($node->children !== []) {
                    $open[$node->id] = $node->depth < $levels;
                }

                $walk($node->children);
            }
        };
        $walk($roots);

        return $open;
    }

    /** @param array<int, list<stdClass>> $byManager */
    private static function node(stdClass $row, array $byManager): OrgNode
    {
        $name = mb_trim($row->name.' '.($row->surname ?? ''));

        return new OrgNode(
            id: (int) $row->id,
            name: $name,
            position: filled($row->position) ? (string) $row->position : null,
            avatarUrl: filled($row->avatar_path) ? Storage::disk('public')->url((string) $row->avatar_path) : null,
            initials: mb_strtoupper(mb_substr((string) $row->name, 0, 1).mb_substr((string) ($row->surname ?? ''), 0, 1)),
            department: filled($row->department_name) ? (string) $row->department_name : null,
            color: self::safeColor($row->department_color),
            depth: (int) $row->depth,
            children: array_map(fn (stdClass $child): OrgNode => self::node($child, $byManager), $byManager[(int) $row->id] ?? []),
        );
    }
}
