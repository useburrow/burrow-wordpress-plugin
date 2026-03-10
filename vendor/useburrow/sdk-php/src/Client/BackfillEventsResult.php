<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

final readonly class BackfillEventsResult
{
    /**
     * @param list<array<string,mixed>> $accepted
     * @param list<array<string,mixed>> $rejected
     * @param list<array{index:int,reason:string,message:string}> $validationRejections
     */
    public function __construct(
        public array $accepted,
        public array $rejected,
        public int $requestedCount,
        public int $acceptedCount,
        public int $rejectedCount,
        public int $validationRejectedCount,
        public array $validationRejections,
        public ?string $latestCursor
    ) {
    }
}
