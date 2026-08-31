<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\TransitionRoleInterface;

/**
 * A plain role vocabulary: two values, no completeness rule. Exercises the
 * generic form field, cardinality check and typo check.
 */
class FixtureReviewRole implements TransitionRoleInterface
{
    public static function getMetadataKey(): string
    {
        return 'review';
    }

    public function getValues(): array
    {
        return ['approve', 'reject'];
    }

    public function getVocabulary(): string
    {
        return 'review';
    }

    public function getFormLabel(): string
    {
        return 'Review role';
    }

    public function getFormHelp(): string
    {
        return 'Fixture vocabulary for the bundle test suite.';
    }

    public function resolve(array $metadata): ?string
    {
        $raw = $metadata[self::getMetadataKey()] ?? null;

        return \is_string($raw) && \in_array($raw, $this->getValues(), true) ? $raw : null;
    }

    public function getRequiredValues(): array
    {
        return [];
    }

    public function getDuplicateMessage(): string
    {
        return 'Two transitions claim the review "{{ role }}" role ({{ transitions }}); exactly one must.';
    }

    public function getMissingMessage(): ?string
    {
        return null;
    }
}
