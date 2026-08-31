<?php

namespace WorkflowConfigurator;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use WorkflowConfigurator\Doctrine\TablePrefixListener;
use WorkflowConfigurator\Repository\WorkflowDefinitionRepository;
use WorkflowConfigurator\Repository\WorkflowPlaceRepository;
use WorkflowConfigurator\Repository\WorkflowTransitionRepository;

/**
 * Operator-editable workflow graphs: the definition/place/transition entities,
 * their repositories, and the schema seam a consumer needs.
 *
 * The bundle ships no migrations — after enabling it, run
 * `doctrine:migrations:diff` and review the generated migration. Every config
 * key corresponds to a behaviour that was hardcoded in the original in-app
 * implementation.
 */
class WorkflowConfiguratorBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('table_prefix')
                    ->defaultValue(TablePrefixListener::DEFAULT_PREFIX)
                    ->info('Prefix for the five workflow tables. The default matches the entity-declared names, so existing consumers of those names need no config; override it when your schema already owns "workflow_*".')
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'WorkflowConfigurator' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'WorkflowConfigurator\Entity',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array{table_prefix: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()
            ->defaults()->autowire()->autoconfigure();

        $services->set(TablePrefixListener::class)
            ->arg('$prefix', $config['table_prefix'])
            ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);

        $services->set(WorkflowDefinitionRepository::class);
        $services->set(WorkflowPlaceRepository::class);
        $services->set(WorkflowTransitionRepository::class);
    }
}
