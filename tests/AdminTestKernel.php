<?php

namespace WorkflowConfigurator\Tests;

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use WorkflowConfigurator\Tests\Fixtures\AdminDashboardController;

/**
 * The TestKernel plus the admin stack — EasyAdmin, Twig, Security and a
 * fixture dashboard — modelling a consumer that wires the bundle's admin
 * layer per the README. The functional smoke suite runs against this.
 */
class AdminTestKernel extends TestKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new TwigBundle();
        yield new \Twig\Extra\TwigExtraBundle\TwigExtraBundle();
        yield new \Symfony\UX\TwigComponent\TwigComponentBundle();
        yield new SecurityBundle();
        yield new EasyAdminBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('framework', [
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'assets' => ['enabled' => true],
            'csrf_protection' => true,
            'translator' => ['enabled' => true, 'fallbacks' => ['en']],
        ]);

        $container->extension('twig', [
            'cache' => false,
        ]);

        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => [],
        ]);

        $container->extension('security', [
            'providers' => [
                'fixture_users' => [
                    'memory' => [
                        'users' => [
                            'admin' => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                        ],
                    ],
                ],
            ],
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext',
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'fixture_users',
                    'logout' => ['path' => '/logout'],
                ],
            ],
        ]);

        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->set(AdminDashboardController::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'easyadmin.routes');
    }
}
