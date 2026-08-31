<?php

namespace WorkflowConfigurator\Validator;

use WorkflowConfigurator\Entity\WorkflowTransition;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class TransitionPlacesBelongToDefinitionValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TransitionPlacesBelongToDefinition) {
            throw new UnexpectedTypeException($constraint, TransitionPlacesBelongToDefinition::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof WorkflowTransition) {
            throw new UnexpectedValueException($value, WorkflowTransition::class);
        }

        $definition = $value->getDefinition();
        if (null === $definition) {
            return;
        }

        foreach (['froms' => $value->getFroms(), 'tos' => $value->getTos()] as $path => $places) {
            foreach ($places as $place) {
                if ($place->getDefinition() !== $definition) {
                    $this->context->buildViolation($constraint->message)
                        ->setParameter('{{ place }}', $place->getName())
                        ->setParameter('{{ actual }}', (string) $place->getDefinition()?->getName())
                        ->setParameter('{{ expected }}', $definition->getName())
                        ->atPath($path)
                        ->addViolation();
                }
            }
        }
    }
}
