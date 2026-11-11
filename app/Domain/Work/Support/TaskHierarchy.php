<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Work\Models\Task;

/** BR-11: a task's parent is optional, in the same project, and the chain never loops. */
final class TaskHierarchy
{
    public static function wouldCycle(?int $taskId, ?int $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }

        if ($taskId !== null && $parentId === $taskId) {
            return true;
        }

        $seen = [];
        $current = $parentId;

        while ($current !== null && ! isset($seen[$current])) {
            if ($taskId !== null && $current === $taskId) {
                return true;
            }

            $seen[$current] = true;
            $current = Task::query()->withTrashed()->whereKey($current)->value('parent_id');
        }

        return false;
    }
}
