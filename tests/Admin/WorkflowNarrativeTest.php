<?php

namespace WorkflowConfigurator\Tests\Admin;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WorkflowConfigurator\Admin\WorkflowNarrative;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\Task\WorkflowTaskMap;
use WorkflowConfigurator\TransitionRoleMap;

/**
 * The workflow read out in sentences (specs/DynamicWorkflows.md §10.6,
 * criteria 32 and 33): one fixture definition holding every kind of trigger
 * and every kind of thing to check. Nothing is persisted — the reading is a
 * function of the entities alone.
 */
class WorkflowNarrativeTest extends KernelTestCase
{
    private function narrative(): WorkflowNarrative
    {
        // Built by hand: nothing in the headless test kernel uses the
        // service, so the container compiles it away.
        return new WorkflowNarrative(
            self::getContainer()->get(TransitionRoleMap::class),
            self::getContainer()->get(WorkflowTaskMap::class),
        );
    }

    /**
     * received → checked → published → read / posted, with a recall, an
     * orphan, an end that has a way out and two follow-ups that go nowhere.
     */
    private function definition(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition()->setName('lane')->setLabel('Lane');

        $places = [];
        foreach (['received' => 'Received', 'checked' => 'Checked', 'published' => 'Published', 'read' => 'Read', 'posted' => 'Sent by post', 'orphan' => 'Orphan'] as $name => $label) {
            $places[$name] = new WorkflowPlace()->setName($name)->setLabel($label);
            $definition->addPlace($places[$name]);
        }
        $definition->setInitialPlace($places['received']);
        $places['read']->setEnd(true);

        $add = static function (string $name, string $from, string $to, array $metadata = []) use ($definition, $places): void {
            $definition->addTransition(new WorkflowTransition()
                ->setName($name)
                ->addFrom($places[$from])
                ->addTo($places[$to])
                ->setMetadata($metadata));
        };

        $add('check', 'received', 'checked', ['task' => 'fork', 'args' => ['otherwise' => 'to_post'], 'next' => 'publish', 'deadline' => ['after' => 'PT1S']]);
        $add('publish', 'checked', 'published', ['task' => 'rotate', 'args' => ['degrees' => 90], 'deadline' => ['after' => 'P7D', 'transition' => 'post_unread']]);
        $add('to_post', 'checked', 'posted');
        $add('mark_read', 'published', 'read', ['review' => 'approve']);
        $add('refuse', 'published', 'read', ['review' => 'reject']);
        $add('post_unread', 'published', 'posted');
        $add('late', 'published', 'posted', ['deadline' => ['after' => 'P1DT12H']]);
        $add('recall', 'published', 'received');
        $add('reopen', 'read', 'received');
        $add('lost', 'orphan', 'read', ['task' => 'rotate', 'args' => ['degrees' => 90], 'next' => 'nowhere', 'deadline' => ['after' => 'P1D', 'transition' => 'publish']]);

        return $definition;
    }

    /**
     * @return array<string, list<string>> sentences by place name
     */
    private function sentencesByPlace(): array
    {
        self::bootKernel();

        $byPlace = [];
        foreach ($this->narrative()->of($this->definition())['places'] as $place) {
            $byPlace[$place['name']] = array_column($place['sentences'], 'text');
        }

        return $byPlace;
    }

    public function testPlacesAreReadBreadthFirstFromTheStartThenTheUnreachable(): void
    {
        self::bootKernel();
        $places = $this->narrative()->of($this->definition())['places'];

        self::assertSame(['received', 'checked', 'published', 'posted', 'read', 'orphan'], array_column($places, 'name'));
        self::assertTrue($places[0]['initial']);
        self::assertFalse($places[5]['reachable']);
    }

    public function testEachKindOfTriggerIsSaid(): void
    {
        $says = $this->sentencesByPlace();

        // A self-firing deadline under a minute.
        self::assertSame(['Straight away, check runs fork and the document moves to Checked.'], $says['received']);

        // What the inbound task hands on: its next, and its "otherwise".
        self::assertContains('When fork finishes, publish runs rotate and the document moves to Published.', $says['checked']);
        self::assertContains('When fork says otherwise, to_post does nothing else and the document moves to Sent by post.', $says['checked']);

        // A deadline on the way in, naming this transition.
        self::assertContains('After 7 days here, post_unread does nothing else and the document moves to Sent by post.', $says['published']);
        // A self-firing deadline no single unit can say.
        self::assertContains('After 1 day 12 hours here, late does nothing else and the document moves to Sent by post.', $says['published']);
        // A role that narrates itself, and one that does not.
        self::assertContains('When a reviewer approves it, mark_read does nothing else and the document moves to Read.', $says['published']);
        self::assertContains('When applied as the review role "reject", refuse does nothing else and the document moves to Read.', $says['published']);
        // Nothing fires it but a person or an API call.
        self::assertContains('When someone applies it, recall does nothing else and the document moves to Received.', $says['published']);
    }

    public function testAPlaceWithNoWayOutReadsByItsEndMark(): void
    {
        self::bootKernel();
        $definition = $this->definition();
        $reopen = $definition->getTransitions()->findFirst(static fn ($i, WorkflowTransition $t): bool => 'reopen' === $t->getName());
        self::assertNotNull($reopen);
        $definition->removeTransition($reopen);

        $closing = array_column($this->narrative()->of($definition)['places'], 'closing', 'name');

        self::assertSame('Documents finish here.', $closing['read']);
        self::assertSame('Nothing moves a document on from here, and it is not marked as an end.', $closing['posted']);
        self::assertNull($closing['published']);
    }

    public function testWhatDoesNotAddUpIsListedToCheck(): void
    {
        self::bootKernel();
        $checks = $this->narrative()->of($this->definition())['checks'];

        self::assertContains('"posted" has no way out and is not marked as an end.', $checks);
        self::assertContains('"read" is marked as an end but "reopen" leaves it.', $checks);
        self::assertContains('"orphan" cannot be reached from the start.', $checks);
        self::assertContains('"lost" names "nowhere" as what follows its task, and no transition is called that.', $checks);
        self::assertContains('"lost" names "publish" as what follows its wait, but "publish" does not start from "read", where "lost" leaves the document.', $checks);
        self::assertCount(5, $checks, 'A workflow that adds up elsewhere raises nothing else.');
    }

    public function testATransitionSaysItsOwnSentenceForEachPlaceItLeaves(): void
    {
        self::bootKernel();
        $definition = $this->definition();
        $recall = $definition->getTransitions()->findFirst(static fn ($i, WorkflowTransition $t): bool => 'recall' === $t->getName());
        self::assertNotNull($recall);

        self::assertSame(
            ['In Published: when someone applies it, recall does nothing else and the document moves to Received.'],
            $this->narrative()->sentencesFor($recall),
        );
    }

    public function testADefinitionWithNothingInItReadsAsNothing(): void
    {
        self::bootKernel();

        self::assertSame(['places' => [], 'checks' => []], $this->narrative()->of(new WorkflowDefinition()->setName('empty')->setLabel('Empty')));
    }
}
