<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class BackfillEventsRequest
{
    /**
     * @param list<array<string,mixed>> $events Backfill events must include source-record `timestamp` per event.
     */
    public function __construct(
        public array $events,
        public BackfillWindow $backfill
    ) {
    }

    /**
     * @return array{
     *   events:list<array<string,mixed>>,
     *   backfill:array<string,string>
     * }
     */
    public function toArray(): array
    {
        return [
            'events' => $this->events,
            'backfill' => $this->backfill->toArray(),
        ];
    }
}
