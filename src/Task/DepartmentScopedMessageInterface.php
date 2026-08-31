<?php

namespace WorkflowConfigurator\Task;

/**
 * Task messages that carry their originating department, enabling
 * message-level pause scopes on shared queues
 * (specs/ProcessControl.md §2a).
 */
interface DepartmentScopedMessageInterface
{
    public function getDepartment(): ?string;
}
