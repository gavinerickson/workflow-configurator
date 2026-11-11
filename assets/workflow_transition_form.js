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
 * browser required-validation ignores them and nothing stale submits), the
 * transition pickers offer the selected definition's transitions (§10.4), and
 * the Timing group shows the inputs its choice needs (§10.3).
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
            // The panel element is its own row: templates/form/transition_metadata.html.twig
            // puts data-task-panel on the wrapper it draws. Walking up to a
            // .form-group would find the row of the whole metadata field and
            // hide every section with it.
            panel.style.display = active ? '' : 'none';
            for (const input of panel.querySelectorAll('input, select, textarea')) {
                input.disabled = !active;
            }
        }
    };

    toggle();
    task.addEventListener('change', toggle);
};

/*
 * Transition pickers (specs/DynamicWorkflows.md §10.4): next, the deadline's
 * transition and transition-typed task parameters. The select arrives with
 * every definition's transition names; here it is narrowed to the chosen
 * definition, each name gains its route, and the text input appears only for
 * "One I have not made yet…". data-transition-picker is
 * {"name": {"defId": "from → to"}, ...}.
 */
const NOT_MADE_YET = '__new__';

const initTransitionPickers = () => {
    const definition = document.querySelector('select[name$="[definition]"]');
    const selects = Array.from(document.querySelectorAll('select[data-transition-picker]'));
    if (null === definition || 0 === selects.length) {
        return;
    }

    for (const select of selects) {
        let routes;
        try {
            routes = JSON.parse(select.dataset.transitionPicker);
        } catch {
            continue;
        }

        const wrap = select.closest('[data-transition-picker-wrap]');
        const other = wrap?.querySelector('[data-transition-picker-other]');
        const hint = wrap?.querySelector('[data-transition-picker-hint]');
        if (!other || !hint) {
            continue;
        }

        const placeholder = select.options[0]?.value === '' ? select.options[0].text : '(none)';
        const notMadeYet = Array.from(select.options).find((option) => option.value === NOT_MADE_YET)?.text ?? 'One I have not made yet…';

        // The name this picker means right now, wherever it is held.
        const current = () => (select.value === NOT_MADE_YET ? other.value.trim() : select.value);
        const known = () => Object.entries(routes)
            .filter(([, byDefinition]) => '' !== definition.value && definition.value in byDefinition)
            .map(([name, byDefinition]) => ({ name, route: byDefinition[definition.value] }));

        const sync = () => {
            const typing = select.value === NOT_MADE_YET;
            other.style.display = typing ? '' : 'none';
            other.disabled = !typing;
            if (!typing) {
                // The task panels re-enable their inputs when shown; an
                // empty input cannot then outrank the select on submit.
                other.value = '';
            }

            const name = other.value.trim();
            if (!typing || '' === name) {
                hint.textContent = '';
            } else if (known().some((transition) => transition.name === name)) {
                hint.textContent = `"${name}" exists in this workflow: pick it from the list instead.`;
            } else {
                hint.textContent = `No transition called "${name}" in this workflow yet.`;
            }
        };

        const refill = () => {
            const name = current();
            const offered = known();

            select.innerHTML = '';
            select.add(new Option(placeholder, ''));
            for (const transition of offered) {
                select.add(new Option(`${transition.name}: ${transition.route}`, transition.name));
            }
            select.add(new Option(notMadeYet, NOT_MADE_YET));

            if ('' === name) {
                select.value = '';
                other.value = '';
            } else if (offered.some((transition) => transition.name === name)) {
                select.value = name;
                other.value = '';
            } else {
                // Named, but not a transition of this workflow: say so
                // rather than silently offering nothing.
                select.value = NOT_MADE_YET;
                other.value = name;
            }
            sync();
        };

        refill();
        definition.addEventListener('change', refill);
        select.addEventListener('change', sync);
        other.addEventListener('input', sync);
    }
};

/*
 * Timing (§10.3): the choice decides which inputs are asked for, and one
 * sentence says what it means with the transition's own places in it.
 */
const initTiming = () => {
    const section = document.querySelector('[data-timing]');
    const radios = Array.from(document.querySelectorAll('input[type="radio"][name$="[metadata][timing]"]'));
    if (null === section || 0 === radios.length) {
        return;
    }

    const detail = section.querySelector('[data-timing-detail]');
    const says = section.querySelector('[data-timing-says]');
    const amount = section.querySelector('input[name$="[timing_amount]"]');
    const exact = section.querySelector('input[name$="[timing_exact]"]');

    const places = (suffix, fallback) => {
        const select = document.querySelector(`select[name$="${suffix}"]`);
        const names = null === select ? [] : Array.from(select.selectedOptions).map((option) => option.text.trim()).filter((text) => '' !== text);

        // TomSelect can leave a selected option listed twice.
        return 0 === names.length ? fallback : Array.from(new Set(names)).join(' or ');
    };

    const update = () => {
        const timing = radios.find((radio) => radio.checked)?.value ?? 'none';
        detail.style.display = 'none' === timing ? 'none' : '';
        for (const part of section.querySelectorAll('[data-timing-for]')) {
            part.style.display = part.dataset.timingFor.split(' ').includes(timing) ? '' : 'none';
        }

        const template = says.dataset[{ self_now: 'saysSelfNow', self_after: 'saysSelfAfter', wait: 'saysWait' }[timing]] ?? '';
        says.textContent = template
            .replace('{from}', places('[froms][]', 'its From place'))
            .replace('{to}', places('[tos][]', 'its To place'));
    };

    update();
    for (const radio of radios) {
        radio.addEventListener('change', update);
    }
    for (const suffix of ['[froms][]', '[tos][]', '[definition]']) {
        document.querySelector(`select[name$="${suffix}"]`)?.addEventListener('change', update);
    }
    // A number takes precedence over an exact duration; typing one retires
    // the other so the form never shows two answers.
    amount?.addEventListener('input', () => {
        if ('' !== amount.value && exact) {
            exact.value = '';
        }
    });
};

const init = () => {
    initTaskPanels();
    initTransitionPickers();
    initTiming();
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
