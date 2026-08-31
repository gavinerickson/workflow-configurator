<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\Task\AsyncTaskMessageInterface;

class TestStampMessage implements AsyncTaskMessageInterface
{
    public function __construct(public readonly ?string $workflowName)
    {
    }
}
