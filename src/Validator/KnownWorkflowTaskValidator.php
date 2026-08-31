<?php

namespace WorkflowConfigurator\Validator;

use WorkflowConfigurator\Deadline;
use WorkflowConfigurator\Task\Args\ArgsSchemaValidator;
use WorkflowConfigurator\Task\WorkflowTaskMap;
use WorkflowConfigurator\TransitionRoleMap;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class KnownWorkflowTaskValidator extends ConstraintValidator
{
    public function __construct(
        private readonly WorkflowTaskMap $taskMap,
        private readonly TransitionRoleMap $roles,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof KnownWorkflowTask) {
            throw new UnexpectedTypeException($constraint, KnownWorkflowTask::class);
        }

        if (!\is_array($value)) {
            return;
        }

        // Deadlines are validated at save time for the same reason task args
        // are: a malformed duration should stop an operator editing a form,
        // not surface hours later when a sweep tries to parse it
        // (specs/DynamicWorkflows.md §8.3).
        // An unrecognised role is a typo, and a typo that silently does
        // nothing is worse than one that stops the form
        // (specs/DigitalFirstDelivery.md §4.4) — one pass per registered
        // vocabulary, so every role key gets the same protection
        // (specs/WorkflowBundleExtraction.md §4).
        // Sub-paths on every violation (§9.4 rule 1): the guided form maps
        // "[task]" and each "[<role key>]" onto their inputs by name,
        // "[deadline]" and "[args][<key>]" via TransitionMetadataType's
        // error_mapping — so an error lands on the field that caused it.
        foreach ($this->roles as $key => $roleProvider) {
            $raw = $value[$key] ?? null;
            if (null !== $raw && (!\is_string($raw) || !\in_array($raw, $roleProvider->getValues(), true))) {
                $this->context->buildViolation($constraint->argsMessage)
                    ->setParameter('{{ task }}', $key)
                    ->setParameter('{{ problem }}', \sprintf(
                        '"%s" is not a %s role; expected one of: %s',
                        \is_scalar($raw) ? (string) $raw : get_debug_type($raw),
                        $roleProvider->getVocabulary(),
                        implode(', ', $roleProvider->getValues()),
                    ))
                    ->atPath('['.$key.']')
                    ->addViolation();
            }
        }

        foreach (Deadline::validate($value) as $problem) {
            $this->context->buildViolation($constraint->argsMessage)
                ->setParameter('{{ task }}', 'deadline')
                ->setParameter('{{ problem }}', $problem)
                ->atPath('[deadline]')
                ->addViolation();
        }

        $task = $value['task'] ?? null;
        if (null === $task || '' === $task) {
            return;
        }

        if (!\is_string($task) || !$this->taskMap->has($task)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ task }}', \is_scalar($task) ? (string) $task : get_debug_type($task))
                ->setParameter('{{ available }}', implode(', ', $this->taskMap->names()) ?: '(none)')
                ->atPath('[task]')
                ->addViolation();

            return;
        }

        // specs/DocumentSubmission.md §7 — args are validated at save time,
        // never at execution. The schema is enforced here, not by the task,
        // so a task cannot render one rule and validate another
        // (specs/DynamicWorkflows.md §9.2); unknown keys are rejected however
        // the metadata was written (§9.4).
        $args = $value['args'] ?? [];
        $args = \is_array($args) ? $args : [];
        $taskService = $this->taskMap->get($task);

        foreach (ArgsSchemaValidator::validate($taskService->describeArgs(), $args) as $key => $keyed) {
            foreach ($keyed as $problem) {
                $this->context->buildViolation($constraint->argsMessage)
                    ->setParameter('{{ task }}', $task)
                    ->setParameter('{{ problem }}', $problem)
                    ->atPath('[args]['.$key.']')
                    ->addViolation();
            }
        }

        // Schema-inexpressible checks (§9.2) stay unattributed: their
        // messages carry their own context (e.g. "postage.rules[0].class").
        foreach ($taskService->validateArgs($args) as $problem) {
            $this->context->buildViolation($constraint->argsMessage)
                ->setParameter('{{ task }}', $task)
                ->setParameter('{{ problem }}', $problem)
                ->addViolation();
        }
    }
}
