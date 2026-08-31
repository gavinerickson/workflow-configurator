<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-001', title: 'Graphs are operator data, compiled at runtime', section: '2.1')]
#[Criterion('An enabled definition compiles and runs with no YAML or container configuration')]
#[Criterion('A missing or disabled workflow is refused loudly, never silently no-opped')]
#[Criterion('A warm cache serves without definition queries, and any ORM edit invalidates it')]
#[Criterion('Dynamic workflows dispatch the same events, in the same order, as statically configured ones')]
final class GraphsAreOperatorData implements RequirementDefinition
{
}
