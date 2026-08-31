<?php

namespace WorkflowConfigurator\Tests;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use WorkflowConfigurator\PlaceOccupancyCheckerInterface;
use WorkflowConfigurator\Tests\Fixtures\CountingOccupancyChecker;
use WorkflowConfigurator\Tests\Fixtures\FixtureLifecycleRole;
use WorkflowConfigurator\Tests\Fixtures\FixtureNoArgsTask;
use WorkflowConfigurator\Tests\Fixtures\FixtureReviewRole;
use WorkflowConfigurator\Tests\Fixtures\FixtureRotateTask;
use WorkflowConfigurator\Tests\Fixtures\FixtureStamperTask;
use WorkflowConfigurator\Tests\Fixtures\RecordingStampTask;
use WorkflowConfigurator\WorkflowConfiguratorBundle;

/**
 * Minimal consumer for the integration suite: FrameworkBundle + Doctrine on
 * sqlite + this bundle, with fixture tasks, roles and an occupancy checker
 * registered the way a real consumer registers its own (autoconfiguration
 * applies the workflow_configurator.* tags via the interfaces).
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new WorkflowConfiguratorBundle(),
            new \RequirementsAsCode\RequirementsAsCodeBundle(),
        ];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'form' => ['enabled' => true, 'csrf_protection' => ['enabled' => false]],
            'property_access' => ['enabled' => true],
            'messenger' => [
                'transports' => [
                    // Captures task dispatches; TaskDispatchSubscriber routes
                    // by TransportNamesStamp, so only existence matters.
                    'stamping' => 'in-memory://',
                ],
            ],
        ]);

        $container->extension('requirements_as_code', [
            // Requirements live with the tests, as in RAC's own repo — they
            // are the package's dev-side compliance register, not shipped API.
            'requirements' => [
                'dir' => 'tests/Requirements',
                'namespace' => 'WorkflowConfigurator\Tests\Requirements',
            ],
            'scan' => [
                // 'source' is where RequirementDefinition classes are scanned
                // for — here that is the dev-side register, not shipped src/.
                'source' => ['dir' => 'tests/Requirements', 'namespace' => 'WorkflowConfigurator\Tests\Requirements\\'],
                'tests' => ['dir' => 'tests', 'namespace' => 'WorkflowConfigurator\Tests\\'],
            ],
            'dashboard' => ['enabled' => false],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => 'sqlite:///%kernel.cache_dir%/test.db',
            ],
            'orm' => [
                'auto_mapping' => false,
            ],
        ]);

        $services = $container->services()
            ->defaults()->autowire()->autoconfigure();

        $services->set(RecordingStampTask::class);
        $services->set(FixtureRotateTask::class);
        $services->set(FixtureStamperTask::class);
        $services->set(FixtureNoArgsTask::class);
        $services->set(FixtureReviewRole::class);
        $services->set(FixtureLifecycleRole::class);

        $services->set(CountingOccupancyChecker::class);
        $services->alias(PlaceOccupancyCheckerInterface::class, CountingOccupancyChecker::class);

        // Nothing in this kernel injects the form factory, so the interface
        // alias would be compiled away; the form tests fetch it directly.
        $services->alias(\Symfony\Component\Form\FormFactoryInterface::class, 'form.factory')->public();
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__).'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__).'/var/log';
    }
}
