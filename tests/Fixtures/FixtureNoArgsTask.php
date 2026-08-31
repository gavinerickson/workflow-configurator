<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\AsyncTaskMessageInterface;
use WorkflowConfigurator\Task\WorkflowTaskInterface;
use WorkflowConfigurator\WorkflowSubjectInterface;

/**
 * A deliberately argument-free task: an empty schema is a legitimate
 * declaration, and every "args" key is then unknown.
 */
class FixtureNoArgsTask implements WorkflowTaskInterface
{
    public static function getName(): string
    {
        return 'noop';
    }

    public static function getQueue(): string
    {
        return 'stamping';
    }

    public function describeArgs(): ArgsSchema
    {
        return new ArgsSchema([]);
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
