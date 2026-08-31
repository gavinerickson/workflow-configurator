<?php

namespace WorkflowConfigurator\Tests\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use WorkflowConfigurator\Controller\Admin\WorkflowDefinitionCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowTransitionCrudController;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\Tests\AdminTestKernel;

/**
 * The consumer's admin experience, end to end over HTTP: dashboard menu,
 * CRUD create through the real EasyAdmin form, the guided transition form
 * with its task panels and provider-rendered role fields, and the Mermaid
 * diagram. This is the layer the bundle's unit suite cannot see — the
 * always-false hasExtension guard shipped green until a consumer hit it.
 */
class AdminSmokeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected static function getKernelClass(): string
    {
        return AdminTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->client->loginUser(new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']));
    }

    private function adminUrl(string $controller, string $action): string
    {
        return self::getContainer()->get(AdminUrlGenerator::class)
            ->setController($controller)
            ->setAction($action)
            ->generateUrl();
    }

    public function testDashboardMenuLinksTheBundleCruds(): void
    {
        $this->client->followRedirects(true);
        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('#main-menu, .sidebar, nav')->text();
        foreach (['Definitions', 'Places', 'Transitions'] as $item) {
            self::assertStringContainsString($item, $menu, $item);
        }
    }

    public function testADefinitionIsCreatedThroughTheRealCrudForm(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl(WorkflowDefinitionCrudController::class, Action::NEW));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['WorkflowDefinition[name]'] = 'smoke';
        $form['WorkflowDefinition[label]'] = 'Smoke';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $definition = $this->entityManager->getRepository(WorkflowDefinition::class)->findOneBy(['name' => 'smoke']);
        self::assertNotNull($definition);
        self::assertFalse($definition->isEnabled(), 'Definitions are built incrementally, disabled by default.');
    }

    public function testTheGuidedTransitionFormRendersPanelsRolesAndDependentSelectData(): void
    {
        $this->seedGraph();

        $crawler = $this->client->request('GET', $this->adminUrl(WorkflowTransitionCrudController::class, Action::NEW));
        self::assertResponseIsSuccessful();

        // Task panels from the fixture tasks' schemas.
        self::assertGreaterThan(0, $crawler->filter('[data-task-panel="rotate"]')->count());
        // Role fields rendered per registered provider.
        $html = $crawler->html();
        self::assertStringContainsString('Review role', $html);
        self::assertStringContainsString('Lifecycle role', $html);
        // Each place option carries its definition id for the dependent
        // selects (the JS itself is browser territory, app-side).
        self::assertGreaterThan(0, $crawler->filter('option[data-definition]')->count());
    }

    public function testTheDiagramRendersMermaid(): void
    {
        $this->seedGraph();

        $crawler = $this->client->request('GET', $this->adminUrl(WorkflowDefinitionCrudController::class, Action::INDEX));
        self::assertResponseIsSuccessful();

        $link = $crawler->selectLink('Diagram');
        self::assertGreaterThan(0, $link->count());
        $crawler = $this->client->click($link->link());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('pre.mermaid')->count());
        self::assertStringContainsString('received', $crawler->filter('pre.mermaid')->text());
    }

    private function seedGraph(): void
    {
        $definition = new WorkflowDefinition()->setName('seeded')->setLabel('Seeded');
        $received = new WorkflowPlace()->setName('received')->setLabel('Received');
        $stamped = new WorkflowPlace()->setName('stamped')->setLabel('Stamped');
        $definition->addPlace($received)->addPlace($stamped);
        $definition->setInitialPlace($received);
        $definition->addTransition(new WorkflowTransition()->setName('stamp')->addFrom($received)->addTo($stamped));
        $definition->setEnabled(true);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
    }
}
