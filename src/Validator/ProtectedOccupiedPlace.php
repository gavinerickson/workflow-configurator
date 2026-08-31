<?php

namespace WorkflowConfigurator\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * specs/DynamicWorkflows.md §6.2 rule 6 — renaming a place any subject
 * currently occupies would strand those subjects (§5.3). Deletion is guarded
 * separately by OccupiedPlaceRemovalListener (no validation runs on delete).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ProtectedOccupiedPlace extends Constraint
{
    public string $message = 'Cannot rename place "{{ place }}": {{ count }} document(s) currently occupy it.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
