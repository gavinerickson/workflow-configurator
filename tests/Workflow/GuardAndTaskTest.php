<?php

namespace WorkflowConfigurator\Tests\Workflow;

use WorkflowConfigurator\Tests\Fixtures\TestStampMessage;
use WorkflowConfigurator\Tests\Fixtures\TestSubject;
use WorkflowConfigurator\DynamicWorkflowRegistry;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * specs/DynamicWorkflows.md §7 criteria 4 and 5.
 */
class GuardAndTaskTest extends WorkflowTestCase
{
    private DynamicWorkflowRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = self::getContainer()->get(DynamicWorkflowRegistry::class);
    }

    public function testGuardAllowsWhenTrue(): void
    {
        $this->createDefinition('guard-true', guard: 'subject.pageCount <= 6');
        $subject = new TestSubject('guard-true', pageCount: 3);

        self::assertTrue($this->registry->get($subject)->can($subject, 'stamp'));
    }

    public function testGuardBlocksWhenFalse(): void
    {
        $this->createDefinition('guard-false', guard: 'subject.pageCount <= 6');
        $subject = new TestSubject('guard-false', pageCount: 10);

        self::assertFalse($this->registry->get($subject)->can($subject, 'stamp'));
    }

    public function testThrowingGuardBlocksInsteadOfFailingOpen(): void
    {
        $this->createDefinition('guard-throws', guard: 'subject.explode()');
        $subject = new TestSubject('guard-throws');

        self::assertFalse($this->registry->get($subject)->can($subject, 'stamp'));
    }

    public function testCompletedTransitionDispatchesTaskMessage(): void
    {
        $this->createDefinition('task-wf', task: 'test_stamp');
        $subject = new TestSubject('task-wf');

        $this->registry->get($subject)->apply($subject, 'stamp');

        // Task messages ride their family transport (specs/ProcessControl.md §3).
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.stamping');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(TestStampMessage::class, $message);
        self::assertSame('task-wf', $message->workflowName);
    }

    public function testTransitionWithoutTaskDispatchesNothing(): void
    {
        $this->createDefinition('no-task-wf');
        $subject = new TestSubject('no-task-wf');

        $this->registry->get($subject)->apply($subject, 'stamp');

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.stamping');
        self::assertCount(0, $transport->getSent());
    }
}
