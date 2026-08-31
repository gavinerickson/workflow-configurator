<?php

namespace WorkflowConfigurator\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * specs/DynamicWorkflows.md §6.2 rule 5 — a transition's "task" metadata must
 * name a task registered in the code-level task map.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class KnownWorkflowTask extends Constraint
{
    public string $message = 'Unknown task "{{ task }}". Available tasks: {{ available }}';
    public string $argsMessage = 'Invalid args for task "{{ task }}": {{ problem }}';
}
