<?php

namespace WorkflowConfigurator;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every tagged TransitionRoleInterface, keyed by metadata key, in
 * tag-priority order — which is also the order the guided form renders the
 * role fields (specs/WorkflowBundleExtraction.md §4).
 *
 * @implements \IteratorAggregate<string, TransitionRoleInterface>
 */
class TransitionRoleMap implements \IteratorAggregate
{
    /** @var array<string, TransitionRoleInterface> */
    private array $roles = [];

    /**
     * @param iterable<TransitionRoleInterface> $roles
     */
    public function __construct(#[AutowireIterator('workflow_configurator.transition_role')] iterable $roles)
    {
        foreach ($roles as $role) {
            $this->roles[$role::getMetadataKey()] = $role;
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->roles);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->roles;
    }
}
