<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-003', title: 'Tasks ride their declared queue', section: '2.3')]
#[Criterion('A completed transition with task metadata dispatches that task\'s message to the transport the task names')]
#[Criterion('A transition without a task dispatches nothing')]
#[Criterion('Task parameters are validated against the declared schema at save time, unknown keys rejected')]
final class TasksRideTheirDeclaredQueue implements RequirementDefinition
{
}
