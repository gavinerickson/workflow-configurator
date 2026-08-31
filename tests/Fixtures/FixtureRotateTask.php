<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\Task\Args\ArgDefinition;
use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\Args\ArgType;
use WorkflowConfigurator\Task\AsyncTaskMessageInterface;
use WorkflowConfigurator\Task\WorkflowTaskInterface;
use WorkflowConfigurator\WorkflowSubjectInterface;

/**
 * A task with a typed schema: an integer choice and a patterned text arg —
 * the shapes the guided form must submit with canonical types.
 */
class FixtureRotateTask implements WorkflowTaskInterface
{
    public static function getName(): string
    {
        return 'rotate';
    }

    public static function getQueue(): string
    {
        return 'stamping';
    }

    public function describeArgs(): ArgsSchema
    {
        return new ArgsSchema([
            new ArgDefinition('degrees', 'Degrees', ArgType::Choice, required: true, default: 180, choices: [90, 180, 270]),
            new ArgDefinition('pages', 'Pages', ArgType::Text, pattern: '/^\d+(-\d+)?$/', patternHint: 'a page or range, e.g. "2" or "1-3"'),
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
