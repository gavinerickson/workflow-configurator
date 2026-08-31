<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\AsyncTaskMessageInterface;
use WorkflowConfigurator\Task\WorkflowTaskInterface;
use WorkflowConfigurator\WorkflowSubjectInterface;

class RecordingStampTask implements WorkflowTaskInterface
{
    public static function getName(): string
    {
        return 'test_stamp';
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
        return isset($args['forbidden']) ? ['"forbidden" is not allowed'] : [];
    }

    public function createMessage(WorkflowSubjectInterface $subject, array $metadata): AsyncTaskMessageInterface
    {
        return new TestStampMessage($subject->getWorkflowName());
    }
}
