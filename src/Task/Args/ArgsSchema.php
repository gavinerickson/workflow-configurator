<?php

namespace WorkflowConfigurator\Task\Args;

/**
 * A task's ordered parameter list (specs/DynamicWorkflows.md §9.2). An empty
 * schema is a legitimate declaration — attach_resources is deliberately
 * argument-free (specs/RuleEngine.md §5.3) — and means every "args" key is
 * unknown (§9.4).
 */
final readonly class ArgsSchema
{
    /**
     * @param list<ArgDefinition> $args in the order the form should render them
     */
    public function __construct(
        public array $args = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ArgDefinition $arg): string => $arg->name, $this->args);
    }

    public function byName(string $name): ?ArgDefinition
    {
        foreach ($this->args as $arg) {
            if ($arg->name === $name) {
                return $arg;
            }
        }

        return null;
    }
}
