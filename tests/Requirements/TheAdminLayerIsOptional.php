<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-007', title: 'The admin layer is optional and consumer-wired', section: '2.8')]
#[Criterion('The admin layer registers exactly when the EasyAdmin bundle is registered in the kernel')]
#[Criterion('A headless consumer\'s container compiles without form or EasyAdmin')]
#[Criterion('A consumer wiring only its dashboard menu creates definitions through the real CRUD form')]
#[Criterion('The guided transition form and the Mermaid diagram render over HTTP')]
final class TheAdminLayerIsOptional implements RequirementDefinition
{
}
