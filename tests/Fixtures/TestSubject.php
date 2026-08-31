<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\WorkflowSubjectInterface;

/**
 * Plain in-memory workflow subject; the real subject arrives with the
 * Document entity (specs/DynamicWorkflows.md §2.4).
 */
class TestSubject implements WorkflowSubjectInterface
{
    private ?string $marking = null;

    public function __construct(
        private readonly ?string $workflowName,
        public int $pageCount = 1,
        public string $destination = 'printhouse-a',
    ) {
    }

    public function getWorkflowName(): ?string
    {
        return $this->workflowName;
    }

    public function getMarking(): ?string
    {
        return $this->marking;
    }

    public function setMarking(string $marking, array $context = []): void
    {
        $this->marking = $marking;
    }

    public function explode(): never
    {
        throw new \RuntimeException('Guard helper that always throws.');
    }
}
