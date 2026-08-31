<?php

namespace WorkflowConfigurator\Validator;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidGuardExpressionValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidGuardExpression) {
            throw new UnexpectedTypeException($constraint, ValidGuardExpression::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        try {
            // Same names the runtime provides (specs/DynamicWorkflows.md §4).
            new ExpressionLanguage()->lint((string) $value, ['subject', 'metadata']);
        } catch (SyntaxError $e) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ error }}', $e->getMessage())
                ->addViolation();
        }
    }
}
