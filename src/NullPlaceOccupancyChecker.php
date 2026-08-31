<?php

namespace WorkflowConfigurator;

/**
 * Default PlaceOccupancyCheckerInterface: reports every place empty, so the
 * occupied-place protections are inert until the consumer aliases the
 * interface to an implementation that counts its own subjects. That is the
 * intended starting state — the bundle cannot know what a subject is.
 */
final class NullPlaceOccupancyChecker implements PlaceOccupancyCheckerInterface
{
    public function countSubjectsIn(string $workflowName, string $placeName): int
    {
        return 0;
    }
}
