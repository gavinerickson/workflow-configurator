<?php

namespace WorkflowConfigurator\Tests\Workflow;

use PHPUnit\Framework\TestCase;
use WorkflowConfigurator\Entity\WorkflowPlace;

/**
 * The end mark on a place (specs/DynamicWorkflows.md §10.5, criterion 31):
 * one key in the free-form metadata, written only by its own input.
 */
class PlaceEndMarkTest extends TestCase
{
    public function testTheMarkLivesInMetadataAndNowhereElse(): void
    {
        $place = new WorkflowPlace()->setMetadata(['color' => '#fff']);
        self::assertFalse($place->isEnd());

        $place->setEnd(true);
        self::assertSame(['color' => '#fff', 'end' => true], $place->getMetadata());

        $place->setEnd(false);
        self::assertSame(['color' => '#fff'], $place->getMetadata());
    }

    public function testTheJsonTextareaNeitherShowsNorReplacesTheMark(): void
    {
        $place = new WorkflowPlace()->setMetadata(['color' => '#fff'])->setEnd(true);

        self::assertStringNotContainsString('end', $place->getMetadataJson());
        self::assertTrue($place->isEnd(), 'Reading the textarea value must not drop the mark.');

        // Whichever order the form writes its two fields in.
        $place->setMetadataJson('{"sla": "P1D"}');
        self::assertSame(['sla' => 'P1D', 'end' => true], $place->getMetadata());

        $place->setMetadataJson('');
        self::assertSame(['end' => true], $place->getMetadata());
        self::assertSame('', $place->getMetadataJson());
    }

    public function testInvalidJsonLeavesTheMarkAlone(): void
    {
        $place = new WorkflowPlace()->setEnd(true);
        $place->setMetadataJson('{not json');

        self::assertTrue($place->isEnd());
        self::assertSame('{not json', $place->getMetadataJson());
    }
}
