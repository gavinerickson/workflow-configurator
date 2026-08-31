<?php

namespace WorkflowConfigurator;

/**
 * specs/DynamicWorkflows.md §5.3 — raised when a destructive edit would
 * strand in-flight subjects.
 */
class OccupiedPlaceException extends \RuntimeException
{
}
