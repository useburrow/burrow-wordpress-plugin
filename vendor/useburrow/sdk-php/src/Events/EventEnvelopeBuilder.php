<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

use InvalidArgumentException;

final class EventEnvelopeBuilder
{
    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    public static function build(array $event): array
    {
        $required = ['organizationId', 'clientId', 'channel', 'event', 'timestamp'];
        foreach ($required as $field) {
            if (!isset($event[$field]) || $event[$field] === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        return [
            'organizationId' => (string) $event['organizationId'],
            'clientId' => (string) $event['clientId'],
            'projectId' => $event['projectId'] ?? null,
            'integrationId' => $event['integrationId'] ?? null,
            'projectSourceId' => $event['projectSourceId'] ?? null,
            'clientSourceId' => $event['clientSourceId'] ?? null,
            'channel' => (string) $event['channel'],
            'event' => (string) $event['event'],
            'timestamp' => (string) $event['timestamp'],
            'source' => isset($event['source']) ? (string) $event['source'] : null,
            'description' => isset($event['description']) ? (string) $event['description'] : null,
            'icon' => isset($event['icon'])
                ? (string) $event['icon']
                : EventIconResolver::resolveIconForEvent((string) $event['channel'], (string) $event['event']),
            'schemaVersion' => (string) ($event['schemaVersion'] ?? '1'),
            'isLifecycle' => (bool) ($event['isLifecycle'] ?? false),
            'entityType' => isset($event['entityType']) ? (string) $event['entityType'] : null,
            'externalEntityId' => isset($event['externalEntityId']) ? (string) $event['externalEntityId'] : null,
            'externalEventId' => isset($event['externalEventId']) ? (string) $event['externalEventId'] : null,
            'state' => isset($event['state']) ? (string) $event['state'] : null,
            'stateChangedAt' => isset($event['stateChangedAt']) ? (string) $event['stateChangedAt'] : null,
            'properties' => is_array($event['properties'] ?? null) ? $event['properties'] : [],
            'tags' => is_array($event['tags'] ?? null) ? $event['tags'] : [],
        ];
    }
}
