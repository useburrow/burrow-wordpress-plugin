<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\FormsContractsResponse;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Transport\HttpResponse;

interface BurrowClientInterface
{
    /**
     */
    public function discover(OnboardingDiscoveryRequest $request): HttpResponse;

    /**
     */
    public function link(OnboardingLinkRequest $request): HttpResponse;

    /**
     */
    public function submitFormsContract(FormsContractSubmissionRequest $request): FormsContractsResponse;

    public function fetchFormsContracts(string $projectId, string $platform): FormsContractsResponse;

    /**
     * @param array<string,mixed> $event
     */
    public function publishEvent(array $event): HttpResponse;

    /**
     * @param callable(BackfillProgressUpdate):void|null $progressCallback
     */
    public function backfillEvents(
        BackfillEventsRequest $request,
        ?BackfillOptions $options = null,
        ?callable $progressCallback = null
    ): BackfillEventsResult;
}
