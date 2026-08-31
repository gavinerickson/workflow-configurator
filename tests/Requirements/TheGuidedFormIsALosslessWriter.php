<?php

namespace WorkflowConfigurator\Tests\Requirements;

use RequirementsAsCode\Attribute\Criterion;
use RequirementsAsCode\Attribute\Requirement;
use RequirementsAsCode\RequirementDefinition;

#[Requirement('REQ-006', title: 'The guided metadata form is a lossless writer', section: '2.7')]
#[Criterion('Guided fields submit the canonical metadata shape with canonical types')]
#[Criterion('Only the selected task\'s panel reaches args; switching task leaks nothing')]
#[Criterion('Keys and values the form cannot represent surface in the advanced field and round-trip unchanged')]
#[Criterion('Keys owned by dedicated inputs are refused in the advanced field')]
final class TheGuidedFormIsALosslessWriter implements RequirementDefinition
{
}
