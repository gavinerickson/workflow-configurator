<?php

namespace WorkflowConfigurator;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Evaluates the guard expression stored in transition metadata
 * (specs/DynamicWorkflows.md §4).
 *
 * Listens on the generic workflow.guard event so it covers every dynamic
 * workflow regardless of name. A guard that throws blocks the transition and
 * logs at error — it must never fail open.
 */
class GuardExpressionSubscriber implements EventSubscriberInterface
{
    private readonly ExpressionLanguage $expressionLanguage;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->expressionLanguage = new ExpressionLanguage();
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.guard' => 'onGuard'];
    }

    /**
     * @param GuardEvent<object> $event
     */
    public function onGuard(GuardEvent $event): void
    {
        $transition = $event->getTransition();
        $expression = $event->getMetadata('guard', $transition);
        if (!\is_string($expression) || '' === $expression) {
            return;
        }

        try {
            $result = $this->expressionLanguage->evaluate($expression, [
                'subject' => $event->getSubject(),
                'metadata' => $event->getMetadata('_all', $transition) ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Workflow guard expression threw; blocking transition.', [
                'workflow' => $event->getWorkflowName(),
                'transition' => $transition->getName(),
                'expression' => $expression,
                'exception' => $e,
            ]);
            $event->addTransitionBlocker(new TransitionBlocker('Guard expression failed to evaluate.', 'guard_error'));

            return;
        }

        if (!$result) {
            $event->addTransitionBlocker(new TransitionBlocker('Blocked by guard expression.', 'guard'));
        }
    }
}
