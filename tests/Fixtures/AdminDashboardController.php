<?php

namespace WorkflowConfigurator\Tests\Fixtures;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use WorkflowConfigurator\Controller\Admin\WorkflowDefinitionCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowPlaceCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowTransitionCrudController;

/**
 * Minimal consumer dashboard: exactly the wiring the README asks of a real
 * consumer — menu items pointing at the bundle's CRUD controllers. The
 * regression this exercises end to end is EasyAdmin actually knowing those
 * controllers (the always-false hasExtension guard).
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class AdminDashboardController extends AbstractDashboardController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator)
    {
    }

    // Land on the definitions index — the EA welcome page renders without
    // the sidebar, and a real consumer lands somewhere useful anyway.
    public function index(): Response
    {
        return $this->redirect(
            $this->adminUrlGenerator
                ->setController(WorkflowDefinitionCrudController::class)
                ->setAction(Action::INDEX)
                ->generateUrl()
        );
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Consumer fixture');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Home', 'fa fa-home');
        yield MenuItem::linkTo(WorkflowDefinitionCrudController::class, 'Definitions', 'fa fa-diagram-project');
        yield MenuItem::linkTo(WorkflowPlaceCrudController::class, 'Places', 'fa fa-circle-dot');
        yield MenuItem::linkTo(WorkflowTransitionCrudController::class, 'Transitions', 'fa fa-arrow-right');
    }
}
