<?php

namespace WorkflowConfigurator\Twig;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use WorkflowConfigurator\Admin\WorkflowAdminContext;
use WorkflowConfigurator\Controller\Admin\WorkflowDefinitionCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowPlaceCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowTransitionCrudController;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Repository\WorkflowPlaceRepository;
use WorkflowConfigurator\Repository\WorkflowTransitionRepository;

/**
 * Builds the workflow context bar that sits above every Workflows admin page:
 * the picker, and tabs into the selected workflow's pieces.
 *
 * Assembled in PHP rather than Twig so the template stays declarative and the
 * URL shapes are testable without rendering a page.
 */
final class WorkflowAdminExtension extends AbstractExtension
{
    public function __construct(
        private readonly WorkflowAdminContext $context,
        private readonly AdminUrlGeneratorInterface $urls,
        private readonly WorkflowPlaceRepository $places,
        private readonly WorkflowTransitionRepository $transitions,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('workflow_admin_bar', $this->bar(...)),
        ];
    }

    /**
     * @return array{
     *     selected: WorkflowDefinition|null,
     *     options: list<array{id: int, label: string, name: string, url: string, selected: bool}>,
     *     clearUrl: string,
     *     tabs: list<array{label: string, url: string, active: bool, count: int|null}>,
     * }
     */
    public function bar(?string $currentControllerFqcn = null): array
    {
        $selected = $this->context->getSelected();

        $options = [];
        foreach ($this->context->definitions() as $definition) {
            $id = $definition->getId();
            if (null === $id) {
                continue;
            }

            $options[] = [
                'id' => $id,
                'label' => $definition->getLabel(),
                'name' => $definition->getName(),
                'url' => $this->contextUrl($currentControllerFqcn, (string) $id),
                'selected' => $selected?->getId() === $id,
            ];
        }

        return [
            'selected' => $selected,
            'options' => $options,
            'clearUrl' => $this->contextUrl($currentControllerFqcn, WorkflowAdminContext::ALL),
            'tabs' => $this->tabs($selected, $currentControllerFqcn),
        ];
    }

    /**
     * The current screen with the context swapped. Staying on the same screen
     * is the point: picking a different workflow while reading transitions
     * should show that workflow's transitions, not send you back to a listing.
     */
    private function contextUrl(?string $controllerFqcn, string $value): string
    {
        $urls = $this->urls
            ->setController($controllerFqcn ?? WorkflowDefinitionCrudController::class)
            ->setAction(Action::INDEX)
            ->set(WorkflowAdminContext::QUERY_PARAM, $value);

        // An explicit filter would outrank the picker on the next request
        // (WorkflowAdminContext::readRequest), so the picker drops it.
        $urls->unset('filters');

        return $urls->generateUrl();
    }

    /**
     * @return list<array{label: string, url: string, active: bool, count: int|null}>
     */
    private function tabs(?WorkflowDefinition $selected, ?string $currentControllerFqcn): array
    {
        if (null === $selected || null === $id = $selected->getId()) {
            return [];
        }

        $tabs = [
            [
                'label' => 'Definition',
                'url' => $this->urls->setController(WorkflowDefinitionCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl(),
                'active' => WorkflowDefinitionCrudController::class === $currentControllerFqcn,
                'count' => null,
            ],
            [
                'label' => 'Places',
                'url' => $this->urls->setController(WorkflowPlaceCrudController::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl(),
                'active' => WorkflowPlaceCrudController::class === $currentControllerFqcn,
                'count' => $this->places->count(['definition' => $id]),
            ],
            [
                'label' => 'Transitions',
                'url' => $this->urls->setController(WorkflowTransitionCrudController::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl(),
                'active' => WorkflowTransitionCrudController::class === $currentControllerFqcn,
                'count' => $this->transitions->count(['definition' => $id]),
            ],
            [
                'label' => 'Diagram',
                'url' => $this->urls->setController(WorkflowDefinitionCrudController::class)->setAction('renderDiagram')->setEntityId($id)->generateUrl(),
                'active' => false,
                'count' => null,
            ],
        ];

        return $tabs;
    }
}
