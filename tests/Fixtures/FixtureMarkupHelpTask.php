<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\Task\Args\ArgDefinition;
use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\Args\ArgType;
use WorkflowConfigurator\Task\AsyncTaskMessageInterface;
use WorkflowConfigurator\Task\WorkflowTaskInterface;
use WorkflowConfigurator\WorkflowSubjectInterface;

/**
 * A task whose help documents a naming pattern in angle brackets — prose, not
 * markup, and the shape a real task reached for when describing
 * "<template>_<n>".
 *
 * EasyAdmin renders a field's help through Twig's `raw` filter, so before
 * TransitionMetadataType escaped these strings the browser parsed the help as
 * an element. `<template>` takes content, so the remainder of the form — every
 * later task panel, the follow-up transition, the deadline inputs, the role
 * selects and the advanced textarea — was swallowed into an inert fragment
 * that neither rendered nor submitted.
 */
class FixtureMarkupHelpTask implements WorkflowTaskInterface
{
    public static function getName(): string
    {
        return 'markup_help';
    }

    public static function getQueue(): string
    {
        return 'async';
    }

    public function describeArgs(): ArgsSchema
    {
        return new ArgsSchema([
            new ArgDefinition(
                'family',
                'Template family',
                ArgType::Text,
                help: 'The n-th reminder is sent as "<template>_<n>", falling back to "<template>_default".',
            ),
        ]);
    }

    public function validateArgs(array $args): array
    {
        return [];
    }

    public function createMessage(WorkflowSubjectInterface $subject, array $metadata): AsyncTaskMessageInterface
    {
        return new TestStampMessage($subject->getWorkflowName());
    }
}
