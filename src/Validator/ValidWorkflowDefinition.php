<?php

namespace WorkflowConfigurator\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint for WorkflowDefinition covering
 * specs/DynamicWorkflows.md §6.2 rules 1–3, plus the cardinality of every
 * registered transition role vocabulary — whose violation messages live on
 * their providers, not here (specs/WorkflowBundleExtraction.md §4).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ValidWorkflowDefinition extends Constraint
{
    public string $initialPlaceMessage = 'The initial place must be one of this workflow\'s places.';
    public string $initialPlaceRequiredMessage = 'An enabled workflow must have an initial place.';
    public string $emptyEndpointsMessage = 'Transition "{{ transition }}" must have at least one "from" and one "to" place.';
    public string $foreignPlaceMessage = 'Transition "{{ transition }}" references places from another workflow.';
    public string $duplicateTransitionMessage = 'A state machine cannot have two transitions named "{{ transition }}" leaving place "{{ place }}".';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
