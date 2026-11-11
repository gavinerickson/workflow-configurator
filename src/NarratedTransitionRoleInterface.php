<?php

namespace WorkflowConfigurator;

/**
 * A role vocabulary that can say, in a clause, what applying one of its
 * values means — "the recipient reads it", "the batch former claims it" —
 * for the workflow read out in words (specs/DynamicWorkflows.md §10.6).
 *
 * Optional, and separate from TransitionRoleInterface so that adding the
 * reading broke no existing provider: one without it is read by its
 * vocabulary and value.
 */
interface NarratedTransitionRoleInterface extends TransitionRoleInterface
{
    /**
     * What happens when this value's event applies the transition, as the
     * clause after "When": lower case, no trailing punctuation. Null for a
     * value with nothing better to say than its name.
     */
    public function getNarrative(string $value): ?string;
}
