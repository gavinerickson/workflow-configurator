<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\PlaceOccupancyCheckerInterface;

/**
 * Occupancy is settable per test (specs/DynamicWorkflows.md §6.2 rule 6);
 * defaults to empty like the production NullPlaceOccupancyChecker.
 */
class CountingOccupancyChecker implements PlaceOccupancyCheckerInterface
{
    /** @var array<string, int> keyed by "workflowName:placeName" */
    public static array $counts = [];

    public static function reset(): void
    {
        self::$counts = [];
    }

    public function countSubjectsIn(string $workflowName, string $placeName): int
    {
        return self::$counts[$workflowName.':'.$placeName] ?? 0;
    }
}
