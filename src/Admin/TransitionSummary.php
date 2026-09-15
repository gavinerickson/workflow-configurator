<?php

namespace WorkflowConfigurator\Admin;

use WorkflowConfigurator\Deadline;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\TransitionRoleMap;

/**
 * A one-line reading of everything a transition carries beyond its route: the
 * task and its follow-up, the deadline, and the role vocabularies that apply
 * it. Shared by the transitions index and the definition hub, so both describe
 * a transition the same way.
 */
class TransitionSummary
{
    public function __construct(
        private readonly TransitionRoleMap $roles,
    ) {
    }

    public function describe(WorkflowTransition $transition): string
    {
        $metadata = $transition->getMetadata();
        $parts = [];

        if (null !== $task = $transition->getTask()) {
            $next = $metadata['next'] ?? null;
            $parts[] = \is_string($next) && '' !== $next
                ? \sprintf('%s → %s', $task, $next)
                : $task;
        }

        $deadline = Deadline::fromTransition($transition);
        if (null !== $deadline) {
            $parts[] = \sprintf(
                'after %s → %s',
                self::describeInterval($deadline->after),
                $deadline->selfFiring ? 'itself' : $deadline->transition,
            );
        }

        foreach ($this->roles->keys() as $roleKey) {
            $value = $metadata[$roleKey] ?? null;
            if (\is_string($value) && '' !== $value) {
                $parts[] = \sprintf('%s: %s', $roleKey, $value);
            }
        }

        return implode(' · ', $parts);
    }

    private static function describeInterval(\DateInterval $interval): string
    {
        $units = [
            'y' => $interval->y, 'm' => $interval->m, 'd' => $interval->d,
            'h' => $interval->h, 'min' => $interval->i, 's' => $interval->s,
        ];

        $said = [];
        foreach ($units as $suffix => $amount) {
            if ($amount > 0) {
                $said[] = $amount.$suffix;
            }
        }

        return [] === $said ? '0s' : implode(' ', $said);
    }
}
