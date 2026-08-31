<?php

namespace WorkflowConfigurator\Validator;

use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\PlaceOccupancyCheckerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class ProtectedOccupiedPlaceValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlaceOccupancyCheckerInterface $occupancyChecker,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ProtectedOccupiedPlace) {
            throw new UnexpectedTypeException($constraint, ProtectedOccupiedPlace::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof WorkflowPlace) {
            throw new UnexpectedValueException($value, WorkflowPlace::class);
        }

        if (null === $value->getId() || null === $value->getDefinition()) {
            return; // New places cannot be occupied.
        }

        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($value);
        $originalName = $original['name'] ?? null;
        if (null === $originalName || $originalName === $value->getName()) {
            return;
        }

        $count = $this->occupancyChecker->countSubjectsIn($value->getDefinition()->getName(), $originalName);
        if ($count > 0) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ place }}', $originalName)
                ->setParameter('{{ count }}', (string) $count)
                ->atPath('name')
                ->addViolation();
        }
    }
}
