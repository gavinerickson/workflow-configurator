<?php

namespace WorkflowConfigurator\Tests\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use PHPUnit\Framework\Attributes\Group;
use RequirementsAsCode\Attribute\Verifies;
use PHPUnit\Framework\TestCase;
use WorkflowConfigurator\Doctrine\TablePrefixListener;
use WorkflowConfigurator\Entity\WorkflowTransition;

#[Group('rac')]
#[Verifies('REQ-008')]
class TablePrefixListenerTest extends TestCase
{
    public function testDefaultPrefixIsANoOp(): void
    {
        $metadata = $this->transitionMetadata();

        $this->listen($metadata, TablePrefixListener::DEFAULT_PREFIX);

        self::assertSame('workflow_transition', $metadata->getTableName());
        self::assertSame('workflow_transition_from', $this->joinTableName($metadata));
    }

    public function testCustomPrefixRenamesTableAndJoinTables(): void
    {
        $metadata = $this->transitionMetadata();

        $this->listen($metadata, 'wc_');

        self::assertSame('wc_transition', $metadata->getTableName());
        self::assertSame('wc_transition_from', $this->joinTableName($metadata));
    }

    public function testForeignEntitiesAreLeftAlone(): void
    {
        /** @var ClassMetadata<object> $metadata */
        $metadata = new ClassMetadata(\stdClass::class);
        $metadata->setPrimaryTable(['name' => 'workflow_definition']);

        $this->listen($metadata, 'wc_');

        self::assertSame('workflow_definition', $metadata->getTableName());
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function listen(ClassMetadata $metadata, string $prefix): void
    {
        $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));
        new TablePrefixListener($prefix)->loadClassMetadata($args);
    }

    /**
     * @return ClassMetadata<WorkflowTransition>
     */
    private function transitionMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(WorkflowTransition::class);
        $metadata->setPrimaryTable(['name' => 'workflow_transition']);
        $metadata->mapManyToMany([
            'fieldName' => 'froms',
            'targetEntity' => 'WorkflowConfigurator\Entity\WorkflowPlace',
            'joinTable' => ['name' => 'workflow_transition_from'],
        ]);

        return $metadata;
    }

    /**
     * @param ClassMetadata<WorkflowTransition> $metadata
     */
    private function joinTableName(ClassMetadata $metadata): string
    {
        $mapping = $metadata->associationMappings['froms'];
        \assert($mapping instanceof ManyToManyOwningSideMapping);

        return $mapping->joinTable->name;
    }
}
