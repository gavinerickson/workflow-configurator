<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-005', title: 'The graph is protected at save time', section: '2.6')]
#[Criterion('An enabled definition requires an initial place belonging to it')]
#[Criterion('Transitions need non-empty froms and tos within their own definition, on every save path')]
#[Criterion('State machines reject duplicate same-named arcs from one place')]
#[Criterion('Guards must parse at save time')]
#[Criterion('A place any subject occupies can be neither renamed nor deleted')]
#[Criterion('Unreachable places warn without rejecting')]
final class TheGraphIsProtectedAtSaveTime implements RequirementDefinition
{
}
