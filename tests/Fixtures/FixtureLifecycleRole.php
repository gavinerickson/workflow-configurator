<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use WorkflowConfigurator\TransitionRoleInterface;

/**
 * A role vocabulary with a completeness rule: declaring either value requires
 * both to be claimed — the digital-first collect/read shape, generically.
 */
class FixtureLifecycleRole implements TransitionRoleInterface
{
    public static function getMetadataKey(): string
    {
        return 'lifecycle';
    }

    public function getValues(): array
    {
        return ['open', 'close'];
    }

    public function getVocabulary(): string
    {
        return 'lifecycle';
    }

    public function getFormLabel(): string
    {
        return 'Lifecycle role';
    }

    public function getFormHelp(): string
    {
        return 'Fixture vocabulary with required values, for the bundle test suite.';
    }

    public function resolve(array $metadata): ?string
    {
        $raw = $metadata[self::getMetadataKey()] ?? null;

        return \is_string($raw) && \in_array($raw, $this->getValues(), true) ? $raw : null;
    }

    public function getRequiredValues(): array
    {
        return ['open', 'close'];
    }

    public function getDuplicateMessage(): string
    {
        return 'Two transitions claim the lifecycle "{{ role }}" role ({{ transitions }}); exactly one must.';
    }

    public function getMissingMessage(): ?string
    {
        return 'This workflow declares lifecycle roles but has no "{{ role }}" transition.';
    }
}
