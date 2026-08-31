<?php

namespace WorkflowConfigurator\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use WorkflowConfigurator\Controller\Admin\WorkflowDefinitionCrudController;
use WorkflowConfigurator\DynamicWorkflowRegistry;
use WorkflowConfigurator\WorkflowConfiguratorBundle;

/**
 * The EasyAdmin layer registers exactly when the EasyAdmin bundle is
 * registered in the kernel. Checked through kernel.bundles because
 * loadExtension runs against the temporary merge builder, where
 * hasExtension() is false for every other bundle — the regression this
 * pins was CRUD controllers silently never registering.
 */
class BundleExtensionTest extends TestCase
{
    /**
     * @param array<string, class-string> $kernelBundles
     */
    private function load(array $kernelBundles): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.bundles', $kernelBundles);

        $extension = new WorkflowConfiguratorBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([], $builder);

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
}
