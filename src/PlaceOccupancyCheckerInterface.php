<?php

namespace WorkflowConfigurator;

/**
 * Answers "how many subjects currently sit in this place?" so destructive
 * edits can be blocked (specs/DynamicWorkflows.md §5.3, §6.2 rule 6).
 * Implemented by DocumentPlaceOccupancyChecker
 * (specs/DocumentSubmission.md §10).
 */
interface PlaceOccupancyCheckerInterface
{
    public function countSubjectsIn(string $workflowName, string $placeName): int;
}
