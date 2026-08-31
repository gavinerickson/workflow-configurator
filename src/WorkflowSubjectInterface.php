<?php

namespace WorkflowConfigurator;

/**
 * A thing that moves through a dynamic workflow (specs/DynamicWorkflows.md §2.4).
 *
 * Subjects store a single current place (state_machine semantics) and the name
 * of the definition that governs them, assigned when they enter the system.
 */
interface WorkflowSubjectInterface
{
    public function getWorkflowName(): ?string;

    public function getMarking(): ?string;

    /**
     * @param array<string, mixed> $context
     */
    public function setMarking(string $marking, array $context = []): void;
}
