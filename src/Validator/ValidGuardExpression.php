<?php

namespace WorkflowConfigurator\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * specs/DynamicWorkflows.md §6.2 rule 4 — guard expressions must parse at
 * save time, never fail at execution time.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ValidGuardExpression extends Constraint
{
    public string $message = 'Guard is not a valid expression: {{ error }}';
}
