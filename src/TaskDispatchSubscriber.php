<?php

namespace WorkflowConfigurator;

use WorkflowConfigurator\Task\WorkflowTaskMap;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Dispatches the Messenger message for a transition's {"task": "..."}
 * metadata once the transition has completed
 * (specs/DynamicWorkflows.md §5.2).
 */
class TaskDispatchSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkflowTaskMap $taskMap,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.completed' => 'onCompleted'];
    }

    /**
     * @param CompletedEvent<object> $event
     */
    public function onCompleted(CompletedEvent $event): void
    {
        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        $taskName = $event->getMetadata('task', $transition);
        if (!\is_string($taskName) || '' === $taskName) {
            return;
        }

        $subject = $event->getSubject();
        if (!$subject instanceof WorkflowSubjectInterface) {
            return;
        }

        // Save-time validation (§6.2 rule 5) makes an unknown task an edit-time
        // error; reaching this log means definition and task map drifted.
        if (!$this->taskMap->has($taskName)) {
            $this->logger->error('Transition references unknown workflow task; nothing dispatched.', [
                'workflow' => $event->getWorkflowName(),
                'transition' => $transition->getName(),
                'task' => $taskName,
            ]);

            return;
        }

        $task = $this->taskMap->get($taskName);
        $metadata = $event->getMetadata('_all', $transition) ?? [];

        // Route to the task's family transport (specs/ProcessControl.md §3);
        // the stamp overrides messenger.yaml routing per message.
        $this->messageBus->dispatch(
            $task->createMessage($subject, \is_array($metadata) ? $metadata : []),
            [new \Symfony\Component\Messenger\Stamp\TransportNamesStamp([$task::getQueue()])],
        );
    }
}
