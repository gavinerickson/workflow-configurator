<?php

namespace WorkflowConfigurator\Task;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The code-level task map (specs/DynamicWorkflows.md §5.2): collects every
 * tagged WorkflowTaskInterface. Save-time validation (§6.2 rule 5) checks
 * transition metadata against names().
 */
class WorkflowTaskMap
{
    /** @var array<string, WorkflowTaskInterface> */
    private array $tasks = [];

    /**
     * @param iterable<WorkflowTaskInterface> $tasks
     */
    public function __construct(#[AutowireIterator('workflow_configurator.task')] iterable $tasks)
    {
        foreach ($tasks as $task) {
            $this->tasks[$task::getName()] = $task;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tasks[$name]);
    }

    public function get(string $name): WorkflowTaskInterface
    {
        return $this->tasks[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown workflow task "%s". Known tasks: %s', $name, implode(', ', $this->names()) ?: '(none)'));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tasks);
    }
}
