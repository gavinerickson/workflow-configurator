<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\Task\Args\ArgDefinition;
use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\Args\ArgType;
use WorkflowConfigurator\Task\AsyncTaskMessageInterface;
use WorkflowConfigurator\Task\WorkflowTaskInterface;
use WorkflowConfigurator\WorkflowSubjectInterface;

/**
 * A task mixing a text arg with an integer arg, for the round-trip tests: a
 * hand-typed string where the integer belongs must surface in the advanced
 * field rather than pre-filling the widget.
 */
class FixtureStamperTask implements WorkflowTaskInterface
{
    public static function getName(): string
    {
        return 'stamper';
    }

    public static function getQueue(): string
    {
        return 'stamping';
    }

    public function describeArgs(): ArgsSchema
    {
        return new ArgsSchema([
            new ArgDefinition('supply_chain_id', 'Supply chain ID', ArgType::Text, pattern: '/^\d{7}$/', patternHint: '7 digits'),
            new ArgDefinition('x', 'X position', ArgType::Integer),
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
