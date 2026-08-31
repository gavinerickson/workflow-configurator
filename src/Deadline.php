<?php

namespace WorkflowConfigurator;

use WorkflowConfigurator\Entity\WorkflowTransition;

/**
 * A transition's optional deadline (specs/DynamicWorkflows.md §8.3).
 *
 * Declared in transition metadata beside `task`, `args` and `next`, so a
 * deadline is operator configuration rather than code:
 *
 *   {"deadline": {"after": "P3D", "transition": "send_to_post"}}
 *
 * It reads as: a document that has rested in the place *this* transition
 * leads to for longer than `after` should have `transition` applied to it.
 *
 * Omitting "transition" makes the deadline **self-firing** (added
 * 2026-08-08, §8.3 amendment): the carrying transition fires itself after a
 * document has rested that long in any of its *from* places. This is how a
 * line's *initial* place auto-advances — an initial place has no inbound
 * transition to hang a deadline on, e.g. a straight-to-post line whose
 * dispatch fires seconds after routing.
 *
 * `after` is an ISO-8601 duration — P3D, PT2H, P1W — chosen over "3 days"
 * because it is unambiguous about whether that means 3 or 72 hours.
 */
final readonly class Deadline
{
    private function __construct(
        public \DateInterval $after,
        public string $transition,
        public bool $selfFiring,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string> human-readable problems; empty means valid
     */
    public static function validate(array $metadata): array
    {
        $raw = $metadata['deadline'] ?? null;
        if (null === $raw) {
            return [];
        }

        if (!\is_array($raw)) {
            return ['"deadline" must be an object with "after" and "transition"'];
        }

        $errors = [];

        $after = $raw['after'] ?? null;
        if (!\is_string($after) || '' === $after) {
            $errors[] = '"deadline.after" is required and must be an ISO-8601 duration, e.g. "P3D"';
        } else {
            try {
                new \DateInterval($after);
            } catch (\Exception) {
                $errors[] = \sprintf('"deadline.after" is not a valid ISO-8601 duration: "%s" (try "P3D", "PT12H")', $after);
            }
        }

        $transition = $raw['transition'] ?? null;
        if (null !== $transition && (!\is_string($transition) || '' === $transition)) {
            $errors[] = '"deadline.transition" must name the transition to apply when the deadline passes (omit it entirely to make this transition fire itself)';
        }

        return $errors;
    }

    public static function fromTransition(WorkflowTransition $transition): ?self
    {
        $raw = $transition->getMetadata()['deadline'] ?? null;
        if (!\is_array($raw)) {
            return null;
        }

        $after = $raw['after'] ?? null;
        if (!\is_string($after)) {
            return null;
        }

        // No target named → the carrier fires itself, watching its froms.
        $target = $raw['transition'] ?? null;
        $selfFiring = !\is_string($target) || '' === $target;

        try {
            return new self(
                new \DateInterval($after),
                $selfFiring ? (string) $transition->getName() : $target,
                $selfFiring,
            );
        } catch (\Exception) {
            return null;
        }
    }

    /** The instant before which a document has waited long enough. */
    public function cutoffFrom(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub($this->after);
    }
}
