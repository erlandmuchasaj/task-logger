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
 * `task-logger.hierarchy_max_depth` levels down, which also bounds it should a manager cycle ever exist.
 */
final class OrgTree
{
    private const string ACTIVE = 'deleted_at IS NULL AND banned_at IS NULL AND anonymized_at IS NULL';

    /** @return list<OrgNode> the roots, each with its reports below it, people sorted by name */
    public static function roots(): array
    {
        $active = self::ACTIVE;

        $rows = DB::select(
            "WITH RECURSIVE org AS (
                SELECT u.id, u.manager_id, u.name, u.surname, u.position, u.avatar_path, 0 AS depth
                FROM users u
                WHERE u.{$active}
                  AND (u.manager_id IS NULL OR NOT EXISTS (
                        SELECT 1 FROM users m WHERE m.id = u.manager_id AND m.deleted_at IS NULL AND m.banned_at IS NULL AND m.anonymized_at IS NULL))
                UNION ALL
                SELECT c.id, c.manager_id, c.name, c.surname, c.position, c.avatar_path, org.depth + 1
                FROM users c
                JOIN org ON c.manager_id = org.id
                WHERE c.{$active} AND org.depth < ?
            )
            SELECT id, manager_id, name, surname, position, avatar_path, depth FROM org ORDER BY depth, name, surname, id",
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
            children: array_map(fn (stdClass $child): OrgNode => self::node($child, $byManager), $byManager[(int) $row->id] ?? []),
        );
    }
}
