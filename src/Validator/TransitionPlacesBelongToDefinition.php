<?php

namespace WorkflowConfigurator\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * §6.2 rule 2, enforced at the transition level: the definition-level check
 * only runs when the definition itself is saved, but transitions are also
 * created and edited through their own screen.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class TransitionPlacesBelongToDefinition extends Constraint
{
    public string $message = 'Place "{{ place }}" belongs to workflow "{{ actual }}", not "{{ expected }}".';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
