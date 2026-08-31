<?php

namespace WorkflowConfigurator\Task;

use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\WorkflowSubjectInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A unit of async work a transition can trigger via {"task": "<name>"}
 * metadata (specs/DynamicWorkflows.md §5.2).
 *
 * Implementations are code: the operator wires *when* a task runs by putting
 * its name on a transition; only developers define *what* it does. The
 * produced message must implement AsyncTaskMessageInterface so it routes to
 * the async transport, and its handler applies the follow-up transition when
 * the work is done (§5.1).
 */
#[AutoconfigureTag('workflow_configurator.task')]
interface WorkflowTaskInterface
{
    /** The name operators reference from transition metadata. */
    public static function getName(): string;

    /**
     * The task-family transport this task's messages ride
     * (specs/ProcessControl.md §3): one of the transports defined in
     * messenger.yaml (ingest, stamping, dispatch, ...), pauseable
     * independently.
     */
    public static function getQueue(): string;

    /**
     * The machine-readable authority on this task's parameters
     * (specs/DynamicWorkflows.md §9.2): drives the guided transition form
     * and is enforced centrally by ArgsSchemaValidator at save time —
     * requiredness, types, choices, patterns, unknown-key rejection — so
     * rendering and validation cannot diverge.
     */
    public function describeArgs(): ArgsSchema;

    /**
     * Validates what the schema cannot express — cross-field rules, nested
     * structures (specs/DynamicWorkflows.md §9.2) — at save time
     * (specs/DocumentSubmission.md §7; extends specs/DynamicWorkflows.md
     * §6.2 rule 5). Schema-expressible rules belong in describeArgs(), not
     * here.
     *
     * @param array<string, mixed> $args
     *
     * @return list<string> human-readable problems; empty means valid
     */
    public function validateArgs(array $args): array;

    /**
     * @param array<string, mixed> $metadata the transition's full metadata
     *                                       (task, args, next, ...) so tasks
     *                                       are parameterisable per transition
     */
    public function createMessage(WorkflowSubjectInterface $subject, array $metadata): AsyncTaskMessageInterface;
}
