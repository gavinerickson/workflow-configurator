<?php

namespace WorkflowConfigurator\Task;

/**
 * Marker for messages produced by workflow tasks
 * (specs/DynamicWorkflows.md §5.2); routed to the async transport in
 * config/packages/messenger.yaml.
 */
interface AsyncTaskMessageInterface
{
}
