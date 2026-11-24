<?php

namespace WorkflowConfigurator\Tests\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use PHPUnit\Framework\Attributes\Group;
use RequirementsAsCode\Attribute\Verifies;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Security\Core\User\InMemoryUser;
use WorkflowConfigurator\Admin\WorkflowAdminContext;
use WorkflowConfigurator\Controller\Admin\WorkflowDefinitionCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowPlaceCrudController;
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
#[Group('rac')]
#[Verifies('REQ-007')]
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

    /**
     * The same URL with the workflow context set — what the picker links to.
     */
    private function adminUrlInContext(string $controller, string $action, string $context): string
    {
        return self::getContainer()->get(AdminUrlGenerator::class)
            ->setController($controller)
            ->setAction($action)
            ->set(WorkflowAdminContext::QUERY_PARAM, $context)
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

    public function testTaskHelpIsRenderedAsTextSoItCannotTruncateTheForm(): void
    {
        $this->seedGraph();

        $crawler = $this->client->request('GET', $this->adminUrl(WorkflowTransitionCrudController::class, Action::NEW));
        self::assertResponseIsSuccessful();
        $html = $crawler->html();

        // The task documents "<template>_<n>"; that is prose about a naming
        // pattern, and must reach the page as characters.
        self::assertStringContainsString('&lt;template&gt;_&lt;n&gt;', $html);
        self::assertStringNotContainsString('<template>', $html, 'Help text opened a real element.');

        // Everything the browser would have lost inside it is still here.
        foreach (['[metadata][next][pick]', '[metadata][timing]', '[metadata][timing_amount]', '[metadata][deadline_transition][pick]', '[metadata][extra]'] as $name) {
            self::assertStringContainsString($name, $html, $name.' did not survive the help text.');
        }
        self::assertGreaterThan(0, $crawler->filter('[data-task-panel="rotate"]')->count());
    }

    public function testEveryGuidedInputKeepsItsLabel(): void
    {
        $this->seedGraph();

        $crawler = $this->client->request('GET', $this->adminUrl(WorkflowTransitionCrudController::class, Action::NEW));
        self::assertResponseIsSuccessful();

        // EasyAdmin hides labels nested inside an ArrayField's widget, which
        // is what a JSON column is styled as; the guided editor draws its own
        // rows so the labels it declares are the labels an operator sees.
        $labels = $crawler->filter('.wc-field > label.wc-label')->each(
            static fn ($node): string => trim($node->text())
        );

        foreach (['Task', 'Then', 'What time does here', 'How long', 'Additional metadata (advanced)'] as $expected) {
            self::assertContains($expected, $labels, $expected.' has no visible label.');
        }

        // Including the parameters of a task panel.
        self::assertContains('Degrees', $labels);
    }

    public function testTheSelectedWorkflowIsRememberedBetweenScreens(): void
    {
        $this->seedGraph();
        $other = $this->seedSecondGraph();

        // Picking a workflow on one screen...
        $crawler = $this->client->request('GET', $this->adminUrlInContext(WorkflowPlaceCrudController::class, Action::INDEX, (string) $other->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('filed', $crawler->filter('table')->text());
        self::assertStringNotContainsString('received', $crawler->filter('table')->text());

        // ...still applies on the next screen, with nothing in the URL.
        $crawler = $this->client->request('GET', $this->adminUrl(WorkflowTransitionCrudController::class, Action::INDEX));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('file_history', $crawler->filter('table')->text());
        self::assertStringNotContainsString('stamp', $crawler->filter('table')->text());
    }

    public function testANewRecordStartsInTheWorkflowInContext(): void
    {
        $this->seedGraph();
        $other = $this->seedSecondGraph();

        $this->client->request('GET', $this->adminUrlInContext(WorkflowPlaceCrudController::class, Action::INDEX, (string) $other->getId()));

        $crawler = $this->client->request('GET', $this->adminUrl(WorkflowTransitionCrudController::class, Action::NEW));
        self::assertResponseIsSuccessful();

        $selected = $crawler->filter('select[name="WorkflowTransition[definition]"] option[selected]');
        self::assertSame(1, $selected->count(), 'The definition should be pre-set from the context.');
        self::assertSame((string) $other->getId(), $selected->attr('value'));
    }

    public function testTheContextIsCleared(): void
    {
        $this->seedGraph();
        $other = $this->seedSecondGraph();

        $this->client->request('GET', $this->adminUrlInContext(WorkflowTransitionCrudController::class, Action::INDEX, (string) $other->getId()));

        $crawler = $this->client->request('GET', $this->adminUrlInContext(WorkflowTransitionCrudController::class, Action::INDEX, WorkflowAdminContext::ALL));
        self::assertResponseIsSuccessful();

        $table = $crawler->filter('table')->text();
        self::assertStringContainsString('stamp', $table);
        self::assertStringContainsString('file_history', $table);
    }

    public function testTheDefinitionPageIsTheWorkflowsHome(): void
    {
        $this->seedGraph();
        $definition = $this->entityManager->getRepository(WorkflowDefinition::class)->findOneBy(['name' => 'seeded']);
        self::assertNotNull($definition);

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(WorkflowDefinitionCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($definition->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        // The graph, in place rather than behind the Diagram action.
        self::assertGreaterThan(0, $crawler->filter('.wc-hub-diagram pre.mermaid')->count());
        self::assertStringContainsString('received', $crawler->filter('pre.mermaid')->text());

        // Both panels, with this workflow's pieces and a way to add more.
        $places = $crawler->filter('.wc-hub-panel')->eq(0);
        self::assertStringContainsString('received', $places->text());
        self::assertStringContainsString('stamped', $places->text());
        self::assertStringContainsString('initial', $places->text());

        $transitions = $crawler->filter('.wc-hub-panel')->eq(1);
        self::assertStringContainsString('stamp', $transitions->text());

        self::assertSame(2, $crawler->filter('.wc-hub-head a')->count(), 'Each panel offers its own add link.');
    }

    public function testTheDefinitionPageSurvivesAGraphThatCannotBeDrawnYet(): void
    {
        // A definition is created empty and built incrementally (§6.2 rule 1).
        // With no places there is no graph to dump, and that must not take the
        // page down: it is the page the operator builds the workflow from.
        $definition = new WorkflowDefinition()->setName('half-built')->setLabel('Half built');
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(WorkflowDefinitionCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($definition->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('pre.mermaid')->count());
        self::assertStringContainsString('No places yet', $crawler->filter('.wc-hub-panel')->eq(0)->text());
        self::assertStringContainsString('Add place', $crawler->filter('.wc-hub-head')->eq(0)->text());
    }

    public function testADefinitionWithoutAnInitialPlaceStillDrawsWhatExists(): void
    {
        // Places but no initial place yet: the graph is drawable and the
        // operator should see it while finishing the wiring.
        $definition = new WorkflowDefinition()->setName('no-initial')->setLabel('No initial');
        $definition->addPlace(new WorkflowPlace()->setName('draft')->setLabel('Draft'));
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(WorkflowDefinitionCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($definition->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('draft', $crawler->filter('.wc-hub-panel')->eq(0)->text());
    }

    public function testTheDefinitionPageReadsTheWorkflowInWords(): void
    {
        $this->seedGraph();
        $definition = $this->entityManager->getRepository(WorkflowDefinition::class)->findOneBy(['name' => 'seeded']);
        self::assertNotNull($definition);
        // A label an operator typed is text, wherever it is shown.
        $definition->getPlaces()->findFirst(static fn ($i, WorkflowPlace $p): bool => 'stamped' === $p->getName())
            ?->setLabel('<b>Stamped</b>');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(WorkflowDefinitionCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($definition->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $words = $crawler->filter('.wc-words');
        self::assertSame(1, $words->count());
        self::assertStringContainsString('When someone applies it, stamp does nothing else and the document moves to <b>Stamped</b>.', (string) preg_replace('/\s+/', ' ', $words->text()));
        self::assertSame(0, $words->filter('.wc-words-sentence b b')->count(), 'A place label opened a real element.');

        // A place with no way out that nobody marked as an end is said so,
        // twice: where it is read, and under the things to check.
        self::assertStringContainsString('not marked as an end', $words->filter('.wc-words-warn')->text());
        self::assertStringContainsString('"stamped" has no way out', $words->filter('.wc-words-checks')->text());
    }

    public function testAPlaceIsMarkedAsAnEndFromItsForm(): void
    {
        $this->seedGraph();
        $place = $this->entityManager->getRepository(WorkflowPlace::class)->findOneBy(['name' => 'stamped']);
        self::assertNotNull($place);
        $place->setMetadata(['color' => '#e0f2fe']);
        $this->entityManager->flush();

        $editUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(WorkflowPlaceCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($place->getId())
            ->generateUrl();

        $crawler = $this->client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="WorkflowPlace"]')->form();
        $end = $form['WorkflowPlace[end]'];
        self::assertInstanceOf(ChoiceFormField::class, $end);
        $end->tick();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->entityManager->clear();
        $place = $this->entityManager->getRepository(WorkflowPlace::class)->findOneBy(['name' => 'stamped']);
        self::assertNotNull($place);
        // The mark is stored beside the other metadata, which it left alone.
        self::assertSame(['color' => '#e0f2fe', 'end' => true], $place->getMetadata());

        // The textarea neither shows the mark nor may write it.
        $crawler = $this->client->request('GET', $editUrl);
        $form = $crawler->filter('form[name="WorkflowPlace"]')->form();
        self::assertSame('{ "color": "#e0f2fe" }', preg_replace('/\s+/', ' ', (string) $form->getValues()['WorkflowPlace[metadataJson]']));
        $form['WorkflowPlace[metadataJson]'] = '{"end": false}';
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Documents finish here', $crawler->filter('.invalid-feedback')->text());
    }

    public function testSavingANameThatMatchesNothingWarnsAndStillSaves(): void
    {
        $this->seedGraph();
        $transition = $this->entityManager->getRepository(WorkflowTransition::class)->findOneBy(['name' => 'stamp']);
        self::assertNotNull($transition);

        $editUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(WorkflowTransitionCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($transition->getId())
            ->generateUrl();

        $crawler = $this->client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        // What the saved transition says, above the form.
        self::assertStringContainsString('stamp does nothing else', $crawler->filter('.wc-says')->text());

        $form = $crawler->filter('form[name="WorkflowTransition"]')->form();
        $form['WorkflowTransition[metadata][task]'] = 'rotate';
        $form['WorkflowTransition[metadata][args_rotate][degrees]'] = '90';
        $form['WorkflowTransition[metadata][next][pick]'] = '__new__';
        $form['WorkflowTransition[metadata][next][other]'] = 'not_made_yet';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->entityManager->clear();
        $transition = $this->entityManager->getRepository(WorkflowTransition::class)->findOneBy(['name' => 'stamp']);
        self::assertSame('not_made_yet', $transition?->getMetadata()['next'] ?? null);

        // Through the dashboard's own redirect to the page that shows it.
        $crawler = $this->client->followRedirect();
        while ($this->client->getResponse()->isRedirection()) {
            $crawler = $this->client->followRedirect();
        }
        self::assertStringContainsString('no transition is called that', $crawler->filter('#flash-messages')->text());
    }

    /** §10.5 — an end place a Timing transition leaves is said, and the save stands. */
    public function testMarkingAnEndThatATimingTransitionLeavesWarnsAndStillSaves(): void
    {
        $this->seedGraph();
        $definition = $this->entityManager->getRepository(WorkflowDefinition::class)->findOneBy(['name' => 'seeded']);
        $received = $this->entityManager->getRepository(WorkflowPlace::class)->findOneBy(['name' => 'received']);
        $stamped = $this->entityManager->getRepository(WorkflowPlace::class)->findOneBy(['name' => 'stamped']);
        self::assertNotNull($definition);
        self::assertNotNull($received);
        self::assertNotNull($stamped);
        // Fires itself: a document resting at `stamped` for a day goes back to `received`.
        $definition->addTransition(new WorkflowTransition()->setName('send_back')->addFrom($stamped)->addTo($received)->setMetadata(['deadline' => ['after' => 'P1D']]));
        $this->entityManager->flush();

        $editUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(WorkflowPlaceCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($stamped->getId())
            ->generateUrl();
        $crawler = $this->client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('count as finished', $crawler->filter('form[name="WorkflowPlace"]')->text());
        $form = $crawler->filter('form[name="WorkflowPlace"]')->form();
        $end = $form['WorkflowPlace[end]'];
        self::assertInstanceOf(ChoiceFormField::class, $end);
        $end->tick();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->entityManager->clear();
        self::assertTrue($this->entityManager->getRepository(WorkflowPlace::class)->findOneBy(['name' => 'stamped'])?->isEnd(), 'saved all the same');

        $crawler = $this->client->followRedirect();
        while ($this->client->getResponse()->isRedirection()) {
            $crawler = $this->client->followRedirect();
        }
        $flash = $crawler->filter('#flash-messages')->text();
        self::assertStringContainsString('Documents finish at "stamped"', $flash);
        self::assertStringContainsString('"send_back" moves them on', $flash);
    }

    private function seedSecondGraph(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition()->setName('second')->setLabel('Second');
        $printed = new WorkflowPlace()->setName('printed')->setLabel('Printed');
        $filed = new WorkflowPlace()->setName('filed')->setLabel('Filed');
        $definition->addPlace($printed)->addPlace($filed);
        $definition->setInitialPlace($printed);
        $definition->addTransition(new WorkflowTransition()->setName('file_history')->addFrom($printed)->addTo($filed));
        $definition->setEnabled(true);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        return $definition;
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
