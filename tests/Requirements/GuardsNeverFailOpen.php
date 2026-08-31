<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-002', title: 'Guards never fail open', section: '2.2')]
#[Criterion('A guard expression allows the transition when true')]
#[Criterion('A guard expression blocks the transition when false')]
#[Criterion('A guard that throws blocks the transition instead of allowing it')]
final class GuardsNeverFailOpen implements RequirementDefinition
{
}
