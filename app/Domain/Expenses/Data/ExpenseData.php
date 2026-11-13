<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Data;

use App\Domain\Expenses\Enums\ExpenseCategory;

/** Input for an expense: the header, the currency of every line, and the lines. */
final readonly class ExpenseData
{
    /** @param  list<ExpenseLineData>  $lines */
    public function __construct(
        public ExpenseCategory $category,
        public string $description,
        public string $currency,
        public array $lines,
        public ?int $projectId = null,
        public ?string $notes = null,
        public ?string $receiptPath = null,
    ) {}
}
