<?php

namespace WorkflowConfigurator\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;

/**
 * Rewrites the bundle entities' table names under the configured prefix.
 *
 * The entities declare their tables explicitly under the default prefix
 * (workflow_definition, workflow_place, workflow_transition and the two join
 * tables), so the default is a no-op and the names never depend on the
 * consumer's naming strategy. A consumer whose schema already owns those
 * names overrides `table_prefix` instead of forking.
 */
final class TablePrefixListener
{
    public const DEFAULT_PREFIX = 'workflow_';

    public function __construct(private readonly string $prefix)
    {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        if (self::DEFAULT_PREFIX === $this->prefix) {
            return;
        }

        $metadata = $args->getClassMetadata();
        if (!str_starts_with($metadata->getName(), 'WorkflowConfigurator\Entity\\')) {
            return;
        }

        $metadata->setPrimaryTable(['name' => $this->rename($metadata->getTableName())]);

        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping instanceof ManyToManyOwningSideMapping) {
                $mapping->joinTable->name = $this->rename($mapping->joinTable->name);
            }
        }
    }

    private function rename(string $table): string
    {
        return str_starts_with($table, self::DEFAULT_PREFIX)
            ? $this->prefix.substr($table, \strlen(self::DEFAULT_PREFIX))
            : $table;
    }
}
