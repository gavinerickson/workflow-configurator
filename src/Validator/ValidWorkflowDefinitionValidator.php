<?php

namespace WorkflowConfigurator\Validator;

use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\TransitionRoleMap;
use WorkflowConfigurator\WorkflowType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class ValidWorkflowDefinitionValidator extends ConstraintValidator
{
    public function __construct(private readonly TransitionRoleMap $roles)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidWorkflowDefinition) {
            throw new UnexpectedTypeException($constraint, ValidWorkflowDefinition::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof WorkflowDefinition) {
            throw new UnexpectedValueException($value, WorkflowDefinition::class);
        }

        $ownPlaces = $value->getPlaces()->toArray();

        // Rule 1: a set initial place must belong to the definition; an unset
        // one is only allowed while the definition is disabled (§6.2 rule 1 —
        // definitions are built incrementally, then enabled).
        $initial = $value->getInitialPlace();
        if (null !== $initial && !\in_array($initial, $ownPlaces, true)) {
            $this->context->buildViolation($constraint->initialPlaceMessage)
                ->atPath('initialPlace')
                ->addViolation();
        } elseif (null === $initial && $value->isEnabled()) {
            $this->context->buildViolation($constraint->initialPlaceRequiredMessage)
                ->atPath('initialPlace')
                ->addViolation();
        }

        $seenStateMachineArcs = [];
        foreach ($value->getTransitions() as $i => $transition) {
            // Rule 2: froms/tos non-empty and within the same definition.
            if ($transition->getFroms()->isEmpty() || $transition->getTos()->isEmpty()) {
                $this->context->buildViolation($constraint->emptyEndpointsMessage)
                    ->setParameter('{{ transition }}', $transition->getName())
                    ->atPath("transitions[$i]")
                    ->addViolation();
            }

            foreach ([...$transition->getFroms(), ...$transition->getTos()] as $place) {
                if (!\in_array($place, $ownPlaces, true)) {
                    $this->context->buildViolation($constraint->foreignPlaceMessage)
                        ->setParameter('{{ transition }}', $transition->getName())
                        ->atPath("transitions[$i]")
                        ->addViolation();
                    break;
                }
            }

            // Rule 3: state machines reject same-named transitions leaving the
            // same place (component invariant).
            if (WorkflowType::StateMachine === $value->getType()) {
                foreach ($transition->getFroms() as $from) {
                    $arc = $transition->getName().' '.$from->getName();
                    if (isset($seenStateMachineArcs[$arc])) {
                        $this->context->buildViolation($constraint->duplicateTransitionMessage)
                            ->setParameter('{{ transition }}', $transition->getName())
                            ->setParameter('{{ place }}', $from->getName())
                            ->atPath("transitions[$i]")
                            ->addViolation();
                    }
                    $seenStateMachineArcs[$arc] = true;
                }
            }
        }

        $this->validateRoles($value);
    }

    /**
     * One generic pass over every registered role vocabulary
     * (specs/WorkflowBundleExtraction.md §4): at most one transition per role
     * value, and a vocabulary's required values must all be claimed once any
     * of its values is. Each provider owns its wording and its spec citation.
     *
     * Both failures are caught at save time rather than at request time,
     * because the alternative is a resolver's caller (a partner callback, the
     * archive endpoint, the batch former) arriving at a workflow that cannot
     * answer it — at which point the operator who mis-wired it is asleep.
     */
    private function validateRoles(WorkflowDefinition $definition): void
    {
        foreach ($this->roles as $role) {
            /** @var array<string, list<string>> $byValue */
            $byValue = [];
            foreach ($definition->getTransitions() as $transition) {
                $value = $role->resolve($transition->getMetadata());
                if (null !== $value) {
                    $byValue[$value][] = $transition->getName();
                }
            }

            // Two transitions claiming one role leaves the resolver choosing
            // arbitrarily, so it is refused rather than resolved by luck.
            foreach ($byValue as $value => $transitions) {
                if (\count($transitions) > 1) {
                    $this->context->buildViolation($role->getDuplicateMessage())
                        ->setParameter('{{ role }}', $value)
                        ->setParameter('{{ transitions }}', implode(', ', $transitions))
                        ->addViolation();
                }
            }

            if ([] === $byValue) {
                continue;
            }
            foreach ($role->getRequiredValues() as $required) {
                if (!isset($byValue[$required])) {
                    $this->context->buildViolation($role->getMissingMessage() ?? throw new \LogicException(\sprintf('%s declares required values but no missing-role message.', $role::class)))
                        ->setParameter('{{ role }}', $required)
                        ->addViolation();
                }
            }
        }
    }
}
