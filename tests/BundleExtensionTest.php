<?php

namespace WorkflowConfigurator\Tests;

use PHPUnit\Framework\Attributes\Group;
use RequirementsAsCode\Attribute\Verifies;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use WorkflowConfigurator\Controller\Admin\WorkflowDefinitionCrudController;
use WorkflowConfigurator\DynamicWorkflowRegistry;
use WorkflowConfigurator\WorkflowCacheInvalidator;
use WorkflowConfigurator\WorkflowConfiguratorBundle;

/**
 * The EasyAdmin layer registers exactly when the EasyAdmin bundle is
 * registered in the kernel. Checked through kernel.bundles because
 * loadExtension runs against the temporary merge builder, where
 * hasExtension() is false for every other bundle — the regression this
 * pins was CRUD controllers silently never registering.
 */
#[Group('rac')]
#[Verifies('REQ-007')]
class BundleExtensionTest extends TestCase
{
    /**
     * @param array<string, class-string> $kernelBundles
     * @param array<string, mixed>        $config
     */
    private function load(array $kernelBundles, array $config = []): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.bundles', $kernelBundles);

        $extension = new WorkflowConfiguratorBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([] === $config ? [] : [$config], $builder);

        return $builder;
    }

    public function testAdminLayerRegistersWhenEasyAdminBundleIsRegistered(): void
    {
        $builder = $this->load(['EasyAdminBundle' => 'EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle']);

        self::assertTrue($builder->hasDefinition(WorkflowDefinitionCrudController::class));
        self::assertTrue($builder->hasDefinition(DynamicWorkflowRegistry::class));
    }

    public function testAdminLayerStaysOutOfAHeadlessConsumer(): void
    {
        $builder = $this->load([]);

        self::assertFalse($builder->hasDefinition(WorkflowDefinitionCrudController::class));
        self::assertTrue($builder->hasDefinition(DynamicWorkflowRegistry::class));
    }

    /**
     * Registry and invalidator read the one configured pool, so a consumer
     * with several hosts can give them a shared one (specs/DynamicWorkflows.md
     * §3); the registry is reset between messages.
     */
    #[Verifies('REQ-001')]
    public function testRegistryAndInvalidatorShareTheConfiguredCachePoolAndTheRegistryResets(): void
    {
        $default = $this->load([]);
        self::assertSame('cache.app', (string) $default->getDefinition(DynamicWorkflowRegistry::class)->getArgument('$cache'));

        $builder = $this->load([], ['cache_pool' => 'cache.shared_pool']);
        self::assertSame('cache.shared_pool', (string) $builder->getDefinition(DynamicWorkflowRegistry::class)->getArgument('$cache'));
        self::assertSame('cache.shared_pool', (string) $builder->getDefinition(WorkflowCacheInvalidator::class)->getArgument('$cache'));
        self::assertSame([['method' => 'reset']], $builder->getDefinition(DynamicWorkflowRegistry::class)->getTag('kernel.reset'));
    }
}
