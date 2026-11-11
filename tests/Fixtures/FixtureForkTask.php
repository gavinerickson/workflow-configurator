<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\Task\Args\ArgDefinition;
use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\Args\ArgType;
use WorkflowConfigurator\Task\AsyncTaskMessageInterface;
use WorkflowConfigurator\Task\WorkflowTaskInterface;
use WorkflowConfigurator\WorkflowSubjectInterface;

/**
 * A task that forks: "next" when it says yes, its transition-typed
 * "otherwise" parameter when it says no — the shape the transition picker
 * and the workflow in words must both understand.
 */
class FixtureForkTask implements WorkflowTaskInterface
{
    public static function getName(): string
    {
        return 'fork';
    }

    public static function getQueue(): string
    {
        return 'stamping';
    }

    public function describeArgs(): ArgsSchema
    {
        return new ArgsSchema([
            new ArgDefinition('otherwise', 'Otherwise', ArgType::Transition, help: 'The transition taken when the answer is no.'),
        ]);
    }

    public function validateArgs(array $args): array
    {
        return [];
    }

    public function createMessage(WorkflowSubjectInterface $subject, array $metadata): AsyncTaskMessageInterface
    {
        return new TestStampMessage($subject->getWorkflowName());
    }
}
