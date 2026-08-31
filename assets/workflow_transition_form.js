/*
 * Dependent selects on the workflow-transition form
 * (specs/DynamicWorkflows.md §6.1): the froms/tos multi-selects only offer
 * places of the definition chosen in the Definition dropdown, and re-filter
 * when it changes. Each place <option> carries its definition id in
 * data-definition (set via choice_attr in WorkflowTransitionCrudController).
 *
 * Works with or without EasyAdmin's TomSelect enhancement: the native
 * <option> list is rebuilt (that is what the form submits), and any TomSelect
 * instance on the field is synced through its API.
 *
 * Also drives the guided metadata editor (§9.3): the Task dropdown shows only
 * the selected task's parameter panel (hidden panels' inputs are disabled so
 * browser required-validation ignores them and nothing stale submits), and
 * the next/deadline-transition inputs get completion lists filtered to the
 * selected definition's transitions (criterion 18).
 */

/* One parameter panel per task; visibility follows the Task dropdown. */
const initTaskPanels = () => {
    const task = document.querySelector('select[name$="[metadata][task]"]');
    const panels = Array.from(document.querySelectorAll('[data-task-panel]'));
    if (null === task || 0 === panels.length) {
        return;
    }

    const toggle = () => {
        for (const panel of panels) {
            const active = '' !== task.value && panel.dataset.taskPanel === task.value;
            const row = panel.closest('.form-group') ?? panel;
            row.style.display = active ? '' : 'none';
            for (const input of panel.querySelectorAll('input, select, textarea')) {
                input.disabled = !active;
            }
        }
    };

    toggle();
    task.addEventListener('change', toggle);
};

/*
 * Completion lists for next/deadline-transition: free text (a follow-up may
 * be named before it exists), suggesting the selected definition's
 * transition names. data-transition-suggest is {"name": ["defId", ...], ...}.
 */
const initTransitionSuggestions = () => {
    const definition = document.querySelector('select[name$="[definition]"]');
    const inputs = Array.from(document.querySelectorAll('input[data-transition-suggest]'));
    if (null === definition || 0 === inputs.length) {
        return;
    }

    inputs.forEach((input, i) => {
        let byName;
        try {
            byName = JSON.parse(input.dataset.transitionSuggest);
        } catch {
            return;
        }

        const datalist = document.createElement('datalist');
        datalist.id = `transition-suggest-${i}`;
        input.insertAdjacentElement('afterend', datalist);
        input.setAttribute('list', datalist.id);

        const refill = () => {
            datalist.innerHTML = '';
            for (const [name, definitions] of Object.entries(byName)) {
                if ('' !== definition.value && definitions.includes(definition.value)) {
                    datalist.append(new Option(name));
                }
            }
        };

        refill();
        definition.addEventListener('change', refill);
    });
};

const init = () => {
    initTaskPanels();
    initTransitionSuggestions();
    const definition = document.querySelector('select[name$="[definition]"]');
    const placeSelects = ['[froms][]', '[tos][]']
        .map((suffix) => document.querySelector(`select[name$="${suffix}"]`))
        .filter((el) => null !== el);
    if (null === definition || 0 === placeSelects.length) {
        return;
    }

    // The full option list, captured once — filtering rebuilds from this.
    const allPlaces = placeSelects.map((select) =>
        Array.from(select.options).map((option) => ({
            value: option.value,
            text: option.text,
            definition: option.dataset.definition ?? '',
            selected: option.selected,
        })),
    );

    const filter = (keepSelection) => {
        placeSelects.forEach((select, i) => {
            const matching = allPlaces[i].filter((place) => '' !== definition.value && place.definition === definition.value);

            select.innerHTML = '';
            for (const place of matching) {
                const option = new Option(place.text, place.value, false, keepSelection && place.selected);
                option.dataset.definition = place.definition;
                select.add(option);
            }

            const tomSelect = select.tomselect;
            if (tomSelect) {
                if (!keepSelection) {
                    tomSelect.clear(true);
                }
                tomSelect.clearOptions();
                for (const place of matching) {
                    tomSelect.addOption({ value: place.value, text: place.text });
                }
                if (keepSelection) {
                    for (const place of matching) {
                        if (place.selected) {
                            tomSelect.addItem(place.value, true);
                        }
                    }
                }
                tomSelect.refreshOptions(false);
            }
        });
    };

    filter(true);
    definition.addEventListener('change', () => filter(false));
};

if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
