<?php
declare(strict_types=1);


final readonly class ImportResult
{
    /**
     * @param list<array{line: int, reason: string}> $errors
     */
    public function __construct(
        public int $imported,
        public array $errors,
    ) {
    }
}
