<?php

declare(strict_types=1);

namespace Modules\Notification\Application;

use Modules\Notification\Contracts\NotificationChannel;

/**
 * The channels this environment has.
 *
 * A registry rather than a match statement so an environment swaps a real provider for a null one
 * by binding, not by editing a service — which is what makes the whole notification path
 * exercisable in a test suite with no SMS bill and no Firebase project.
 */
final class ChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    /**
     * @param  iterable<NotificationChannel>  $channels
     */
    public function __construct(iterable $channels = [])
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->name()] = $channel;
        }
    }

    public function get(string $name): ?NotificationChannel
    {
        return $this->channels[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function configured(): array
    {
        return array_values(array_map(
            static fn (NotificationChannel $channel): string => $channel->name(),
            array_filter(
                $this->channels,
                static fn (NotificationChannel $channel): bool => $channel->isConfigured(),
            ),
        ));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->channels);
    }
}
