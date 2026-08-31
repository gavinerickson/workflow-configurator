<?php

namespace WorkflowConfigurator;

use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use WorkflowConfigurator\Controller\Admin\WorkflowDefinitionCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowPlaceCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowTransitionCrudController;
use WorkflowConfigurator\Doctrine\TablePrefixListener;
use WorkflowConfigurator\Form\TransitionMetadataType;
use WorkflowConfigurator\Repository\WorkflowDefinitionRepository;
use WorkflowConfigurator\Repository\WorkflowPlaceRepository;
use WorkflowConfigurator\Repository\WorkflowTransitionRepository;
use WorkflowConfigurator\Task\WorkflowTaskMap;
use WorkflowConfigurator\Validator\KnownWorkflowTaskValidator;
use WorkflowConfigurator\Validator\ProtectedOccupiedPlaceValidator;
use WorkflowConfigurator\Validator\TransitionPlacesBelongToDefinitionValidator;
use WorkflowConfigurator\Validator\ValidGuardExpressionValidator;
use WorkflowConfigurator\Validator\ValidWorkflowDefinitionValidator;

/**
 * Operator-editable workflow graphs over Symfony Workflow. The operator
 * configures the graph; behaviour attached to transitions remains code,
 * supplied by the consumer through the workflow_configurator.task and
 * workflow_configurator.transition_role tags (both applied automatically to
 * implementations via #[AutoconfigureTag] on the interfaces).
 *
 * The bundle ships no migrations — after enabling it, run
 * `doctrine:migrations:diff` and review the generated migration. The admin
 * layer (CRUD, diagram, guided transition form) registers only when
 * EasyAdmin and the Form component are installed; without them the bundle is
 * a headless workflow store, which is a supported configuration.
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

        if (interface_exists(AssetMapperInterface::class)) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [__DIR__.'/../assets' => 'workflow-configurator'],
                ],
            ]);
        }
    }

    /**
     * @param array{table_prefix: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Belt and braces with the #[AutoconfigureTag] attributes on the
        // interfaces: the explicit registration does not depend on the
        // consumer's attribute-autoconfiguration behaviour.
        $builder->registerForAutoconfiguration(Task\WorkflowTaskInterface::class)
            ->addTag('workflow_configurator.task');
        $builder->registerForAutoconfiguration(TransitionRoleInterface::class)
            ->addTag('workflow_configurator.transition_role');

        $services = $container->services()
            ->defaults()->autowire()->autoconfigure();

        $services->set(TablePrefixListener::class)
            ->arg('$prefix', $config['table_prefix'])
            ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);

        $services->set(WorkflowDefinitionRepository::class);
        $services->set(WorkflowPlaceRepository::class);
        $services->set(WorkflowTransitionRepository::class);

        // Runtime: registry + the listeners that keep it honest.
        $services->set(DynamicWorkflowRegistry::class);
        $services->set(WorkflowCacheInvalidator::class);
        $services->set(GuardExpressionSubscriber::class);
        $services->set(TaskDispatchSubscriber::class);
        $services->set(ReachabilityChecker::class);
        $services->set(OccupiedPlaceRemovalListener::class);

        // Consumer-supplied behaviour, collected by tag.
        $services->set(WorkflowTaskMap::class);
        $services->set(TransitionRoleMap::class);

        // Occupancy is inert until the consumer aliases the interface to an
        // implementation that counts its own subjects.
        $services->set(NullPlaceOccupancyChecker::class);
        $services->alias(PlaceOccupancyCheckerInterface::class, NullPlaceOccupancyChecker::class);

        $services->set(ValidWorkflowDefinitionValidator::class);
        $services->set(KnownWorkflowTaskValidator::class);
        $services->set(TransitionPlacesBelongToDefinitionValidator::class);
        $services->set(ValidGuardExpressionValidator::class);
        $services->set(ProtectedOccupiedPlaceValidator::class);

        if (class_exists(AbstractType::class)) {
            $services->set(TransitionMetadataType::class);
        }

        // Keyed on the EasyAdmin *bundle* being registered, not the package
        // being installed — a dev dependency must not activate the layer.
        if ($builder->hasExtension('easy_admin')) {
            $services->set(WorkflowDefinitionCrudController::class);
            $services->set(WorkflowPlaceCrudController::class);
            $services->set(WorkflowTransitionCrudController::class);
        }
    }
}
