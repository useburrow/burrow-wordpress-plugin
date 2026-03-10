<?php

declare(strict_types=1);

namespace Burrow\Sdk\Transport;

interface ConcurrentHttpTransportInterface extends HttpTransportInterface
{
    /**
     * @param list<array{
     *   url:string,
     *   headers:array<string,string>,
     *   payload:array<string,mixed>
     * }> $requests
     *
     * @return list<HttpResponse>
     */
    public function postConcurrent(array $requests): array;
}
