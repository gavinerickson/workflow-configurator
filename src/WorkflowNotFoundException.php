<?php

namespace WorkflowConfigurator;

/**
 * Thrown when a subject references a workflow that is missing or disabled
 * (specs/DynamicWorkflows.md §3) — callers must not silently no-op.
 */
class WorkflowNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(\sprintf('Workflow definition "%s" does not exist or is disabled.', $name));
    }

    public static function forSubjectWithoutName(object $subject): self
    {
        return new self(\sprintf('Subject of class %s has no workflow name assigned.', $subject::class));
    }
}
