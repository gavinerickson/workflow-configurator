<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-004', title: 'Transition roles are a provider contract', section: '2.4')]
#[Criterion('Two transitions claiming one role value are refused at save')]
#[Criterion('A vocabulary\'s required values are enforced once any of its values is declared, and only then')]
#[Criterion('An unrecognised role value is refused as a typo, never silently ignored')]
#[Criterion('Each registered provider renders its own form field, stored under its key')]
final class TransitionRolesAreAProviderContract implements RequirementDefinition
{
}
