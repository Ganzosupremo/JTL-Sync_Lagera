(() => {
    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

    const sortableValue = (cell, type) => {
        const raw = (cell?.dataset.sortValue ?? cell?.textContent ?? '').trim();
        if (type !== 'date') {
            return { empty: raw === '', value: raw };
        }

        const timestamp = Date.parse(raw);
        return {
            empty: raw === '' || Number.isNaN(timestamp),
            value: Number.isNaN(timestamp) ? raw : timestamp,
        };
    };

    document.querySelectorAll('[data-sort-table]').forEach((table) => {
        const body = table.tBodies[0];
        if (!body) return;

        let activeKey = null;
        let direction = 1;

        table.querySelectorAll('.table-sort').forEach((button) => {
            const header = button.closest('th');
            if (header) header.setAttribute('aria-sort', 'none');
            button.removeAttribute('aria-sort');

            button.addEventListener('click', () => {
                const key = button.dataset.sortKey || '';
                direction = activeKey === key ? direction * -1 : 1;
                activeKey = key;

                table.querySelectorAll('.table-sort').forEach((other) => {
                    const active = other === button;
                    const indicator = other.querySelector('.sort-indicator');
                    const header = other.closest('th');
                    if (header) {
                        header.setAttribute('aria-sort', active ? (direction === 1 ? 'ascending' : 'descending') : 'none');
                    }
                    if (indicator) indicator.textContent = active ? (direction === 1 ? '↑' : '↓') : '↕';
                });

                const type = button.dataset.sortType || 'text';
                const columnIndex = button.parentElement.cellIndex;
                const emptyRow = body.querySelector('.table-search-empty');
                const rows = Array.from(body.rows)
                    .filter((row) => !row.classList.contains('table-search-empty'))
                    .map((row, index) => ({ row, index }));
                rows.sort((left, right) => {
                    const leftValue = sortableValue(left.row.cells[columnIndex], type);
                    const rightValue = sortableValue(right.row.cells[columnIndex], type);
                    if (leftValue.empty !== rightValue.empty) return leftValue.empty ? 1 : -1;

                    const comparison = type === 'date'
                        ? (leftValue.value < rightValue.value ? -1 : (leftValue.value > rightValue.value ? 1 : 0))
                        : collator.compare(leftValue.value, rightValue.value);
                    return comparison === 0 ? left.index - right.index : comparison * direction;
                });
                rows.forEach(({ row }) => body.appendChild(row));
                if (emptyRow) body.appendChild(emptyRow);
            });
        });
    });

    const normalizeSearchText = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .replace(/\s+/g, ' ')
        .trim();

    const rowSearchText = (row) => {
        const controlValues = Array.from(row.querySelectorAll('input, select, textarea'))
            .flatMap((control) => {
                if (control instanceof HTMLSelectElement) {
                    const selected = Array.from(control.selectedOptions).map((option) => option.textContent ?? '');
                    return [control.value, ...selected];
                }

                return [control.value ?? ''];
            });

        return normalizeSearchText([row.textContent ?? '', ...controlValues].join(' '));
    };

    const tableSearchControllers = new Map();

    document.querySelectorAll('table').forEach((table, index) => {
        const body = table.tBodies[0];
        if (!body || body.rows.length === 0) return;

        const rows = Array.from(body.rows);
        const searchTexts = new Map(rows.map((row) => [row, rowSearchText(row)]));
        const filters = new Map();
        const tableKey = table.dataset.sortTable || `table-${index + 1}`;
        const heading = table.closest('section')?.querySelector('h2, h3')?.textContent?.trim() || 'esta tabla';
        const toolbar = document.createElement('div');
        const inputId = `table-search-${index + 1}`;
        toolbar.className = 'table-search';
        toolbar.innerHTML = `
            <label class="sr-only" for="${inputId}">Buscar en ${heading}</label>
            <input id="${inputId}" type="search" placeholder="Buscar en ${heading}..." autocomplete="off">
            <span class="table-search-count" aria-live="polite"></span>
            <button class="button secondary small" type="button">Limpiar</button>
        `;

        const searchHost = table.closest('.scroll-table') || table;
        searchHost.parentNode?.insertBefore(toolbar, searchHost);

        const input = toolbar.querySelector('input');
        const count = toolbar.querySelector('.table-search-count');
        const clear = toolbar.querySelector('button');
        const emptyRow = document.createElement('tr');
        emptyRow.className = 'table-search-empty';
        emptyRow.hidden = true;
        emptyRow.innerHTML = `<td colspan="${Math.max(1, table.tHead?.rows[0]?.cells.length || 1)}">Sin coincidencias para esta búsqueda.</td>`;
        body.appendChild(emptyRow);

        const apply = () => {
            const tokens = normalizeSearchText(input?.value ?? '').split(' ').filter(Boolean);
            let visible = 0;

            rows.forEach((row) => {
                const text = searchTexts.get(row) || '';
                const matchesText = tokens.every((token) => text.includes(token));
                const matchesFilters = Array.from(filters.values()).every((filter) => filter(row));
                row.hidden = !(matchesText && matchesFilters);
                if (!row.hidden) visible++;
            });

            emptyRow.hidden = visible !== 0;
            if (count) count.textContent = `${visible} de ${rows.length}`;
        };

        input?.addEventListener('input', apply);
        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') event.preventDefault();
        });
        clear?.addEventListener('click', () => {
            if (input) input.value = '';
            apply();
            input?.focus();
        });

        tableSearchControllers.set(tableKey, {
            apply,
            setFilter(name, filter) {
                if (typeof filter === 'function') filters.set(name, filter);
                else filters.delete(name);
                apply();
            },
        });
        apply();
    });

    const jtlFilterForm = document.querySelector('[data-client-filter-table="jtl-orders"]');
    const jtlTableSearch = tableSearchControllers.get('jtl-orders');

    if (jtlFilterForm && jtlTableSearch) {
        const customerInput = jtlFilterForm.querySelector('[name="jtl_customer"]');
        const mappedCustomerSelect = jtlFilterForm.querySelector('[name="jtl_mapped_customer"]');
        const clearButton = jtlFilterForm.querySelector('[data-clear-jtl-filters]');
        const bulkForm = document.querySelector('[data-jtl-bulk-form]');

        const applyJtlFilters = () => {
            const customer = normalizeSearchText(customerInput?.value ?? '');
            const mappedCustomer = mappedCustomerSelect?.value ?? '';

            jtlTableSearch.setFilter('jtl-customer', (row) => {
                if (customer && !normalizeSearchText(row.dataset.jtlContact).includes(customer)) return false;
                if (mappedCustomer === '__unmapped__') return row.dataset.jtlMapped !== '1';
                if (mappedCustomer) return row.dataset.jtlMappedCustomer === mappedCustomer;
                return true;
            });

            if (bulkForm) {
                const hiddenCustomer = bulkForm.querySelector('[name="jtl_customer"]');
                const hiddenMappedCustomer = bulkForm.querySelector('[name="jtl_mapped_customer"]');
                const submit = bulkForm.querySelector('button[type="submit"]');
                if (hiddenCustomer) hiddenCustomer.value = customerInput?.value ?? '';
                if (hiddenMappedCustomer) hiddenMappedCustomer.value = mappedCustomer;
                if (submit) {
                    submit.disabled = mappedCustomer === '__unmapped__';
                    submit.textContent = mappedCustomer === '__unmapped__'
                        ? 'Mapear antes de enviar'
                        : (customer || mappedCustomer ? 'Enviar filtradas a Packiyo' : 'Enviar todas a Packiyo');
                }
            }
        };

        jtlFilterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            applyJtlFilters();
        });
        customerInput?.addEventListener('input', applyJtlFilters);
        mappedCustomerSelect?.addEventListener('change', applyJtlFilters);
        clearButton?.addEventListener('click', () => {
            if (customerInput) customerInput.value = '';
            if (mappedCustomerSelect) mappedCustomerSelect.value = '';
            applyJtlFilters();
        });
        applyJtlFilters();
    } else {
        document.querySelector('[data-clear-jtl-filters]')?.addEventListener('click', (event) => {
            const url = event.currentTarget.dataset.clearUrl;
            if (url) window.location.assign(url);
        });
    }

    const correctionForm = document.querySelector('[data-correction-selected-form]');
    if (correctionForm) {
        const table = document.querySelector('[data-sort-table="order-corrections"]');
        const checkboxes = Array.from(document.querySelectorAll('[data-correction-order-checkbox]'))
            .filter((checkbox) => !checkbox.disabled);
        const selectAll = document.querySelector('[data-correction-select-all]');
        const count = correctionForm.querySelector('[data-correction-selection-count]');
        const executeButton = correctionForm.querySelector('[data-correction-execute]');
        const maxOrders = Number.parseInt(correctionForm.dataset.correctionMaxOrders || '1', 10);

        const updateCorrectionSelection = () => {
            const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
            if (count) count.textContent = `${selected} orden(es) seleccionada(s)`;
            if (selectAll) {
                const visible = checkboxes.filter((checkbox) => !checkbox.closest('tr')?.hidden);
                selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
                selectAll.indeterminate = visible.some((checkbox) => checkbox.checked) && !selectAll.checked;
            }
        };

        selectAll?.addEventListener('change', () => {
            checkboxes.forEach((checkbox) => {
                if (!checkbox.closest('tr')?.hidden) checkbox.checked = selectAll.checked;
            });
            updateCorrectionSelection();
        });
        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateCorrectionSelection));
        correctionForm.addEventListener('submit', (event) => {
            const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
            if (selected === 0) {
                event.preventDefault();
                window.alert('Selecciona al menos una orden.');
                return;
            }
            if (event.submitter === executeButton) {
                if (selected > maxOrders) {
                    event.preventDefault();
                    window.alert(maxOrders === 1
                        ? 'Para la primera prueba selecciona solamente una orden.'
                        : `Selecciona como maximo ${maxOrders} ordenes por ejecucion.`);
                    return;
                }
                if (!window.confirm(`Se corregiran ${selected} orden(es) en Packiyo. ¿Quieres continuar?`)) {
                    event.preventDefault();
                }
            }
        });
        table?.addEventListener('input', updateCorrectionSelection);
        updateCorrectionSelection();
    }

    document.querySelector('[data-correction-batch-form]')?.addEventListener('submit', (event) => {
        if (!window.confirm('Se enviaran a Packiyo las siguientes 10 ordenes pendientes como maximo. Las ya corregidas y las lineas JTL-LINE canceladas se omitiran. ¿Quieres continuar?')) {
            event.preventDefault();
        }
    });
})();
