<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-008', title: 'The schema is five tables, prefix-configurable', section: '2.9')]
#[Criterion('The default prefix leaves the entity-declared workflow_ names untouched')]
#[Criterion('A table_prefix override renames all five tables, join tables included')]
#[Criterion('Entities outside the bundle are never renamed')]
final class TheSchemaIsFiveTablesPrefixConfigurable implements RequirementDefinition
{
}
