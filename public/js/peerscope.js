(() => {
    'use strict';

    const collator = new Intl.Collator('ja', {
        numeric: true,
        sensitivity: 'base',
    });

    const getSortValue = (row, columnIndex) => {
        const cell = row.cells[columnIndex];

        return cell?.dataset.sortValue ?? cell?.textContent.trim() ?? '';
    };

    const compareRows = (columnIndex, type, direction) => (a, b) => {
        const aValue = getSortValue(a, columnIndex);
        const bValue = getSortValue(b, columnIndex);
        const multiplier = direction === 'asc' ? 1 : -1;

        if (type === 'number') {
            return ((Number(aValue) || 0) - (Number(bValue) || 0)) * multiplier;
        }

        return collator.compare(aValue, bValue) * multiplier;
    };

    const setSortState = (table, activeButton, direction) => {
        table.querySelectorAll('.table-sort-button').forEach((button) => {
            const isActive = button === activeButton;

            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-sort', isActive ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
            button.dataset.sortDirection = isActive ? direction : '';
        });
    };

    const sortTable = (table, button) => {
        const tbody = table.tBodies[0];
        const columnIndex = Number(button.dataset.sortColumn);
        const type = button.dataset.sortType ?? 'text';
        const currentDirection = button.dataset.sortDirection;
        const defaultDirection = button.dataset.sortDefault ?? 'asc';
        const nextDirection = currentDirection === 'asc' ? 'desc' : currentDirection === 'desc' ? 'asc' : defaultDirection;

        if (!tbody || Number.isNaN(columnIndex)) {
            return;
        }

        const rows = Array.from(tbody.rows).filter((row) => row.cells.length > columnIndex);

        rows
            .map((row, index) => ({ row, index }))
            .sort((a, b) => {
                const result = compareRows(columnIndex, type, nextDirection)(a.row, b.row);

                return result === 0 ? a.index - b.index : result;
            })
            .forEach(({ row }) => tbody.appendChild(row));

        setSortState(table, button, nextDirection);
    };

    document.querySelectorAll('.js-sortable-table').forEach((table) => {
        table.querySelectorAll('.table-sort-button').forEach((button) => {
            button.setAttribute('aria-sort', 'none');
            button.addEventListener('click', () => sortTable(table, button));
        });
    });

    document.querySelectorAll('[data-watch-source-form]').forEach((form) => {
        const sourceType = form.querySelector('[data-source-type]');
        const htmlSettings = form.querySelector('[data-html-settings]');
        const scheduleType = form.querySelector('[data-schedule-type]');
        const schedulePanels = form.querySelectorAll('[data-schedule-panel]');
        const scheduleTimePanel = form.querySelector('[data-schedule-time-panel]');
        const intervalInput = form.querySelector('#crawl_interval_minutes');
        const scheduleTimeInput = form.querySelector('#schedule_time');
        const htmlRequiredInputs = ['#list_selector', '#title_selector', '#url_selector']
            .map((selector) => form.querySelector(selector))
            .filter(Boolean);

        const updateSourceSettings = () => {
            const isHtmlDetail = sourceType?.value === 'html';

            htmlSettings?.classList.toggle('d-none', !isHtmlDetail);
            htmlRequiredInputs.forEach((input) => {
                input.required = isHtmlDetail;
            });
        };

        const updateScheduleSettings = () => {
            const selectedType = scheduleType?.value ?? 'interval';

            schedulePanels.forEach((panel) => {
                panel.classList.toggle('d-none', panel.dataset.schedulePanel !== selectedType);
            });

            scheduleTimePanel?.classList.toggle('d-none', selectedType === 'interval');

            if (intervalInput) {
                intervalInput.required = selectedType === 'interval';
            }

            if (scheduleTimeInput) {
                scheduleTimeInput.required = selectedType !== 'interval';
            }
        };

        sourceType?.addEventListener('change', updateSourceSettings);
        scheduleType?.addEventListener('change', updateScheduleSettings);
        updateSourceSettings();
        updateScheduleSettings();
    });

    const directoryPicker = document.getElementById('directoryPickerModal');

    if (directoryPicker) {
        const fallbackModal = {
            show: () => {
                directoryPicker.style.display = 'block';
                directoryPicker.removeAttribute('aria-hidden');
                directoryPicker.setAttribute('aria-modal', 'true');
                directoryPicker.classList.add('show');
                document.body.classList.add('modal-open');
            },
            hide: () => {
                directoryPicker.classList.remove('show');
                directoryPicker.style.display = 'none';
                directoryPicker.setAttribute('aria-hidden', 'true');
                directoryPicker.removeAttribute('aria-modal');
                document.body.classList.remove('modal-open');
            },
        };
        const modal = window.bootstrap ? new window.bootstrap.Modal(directoryPicker) : fallbackModal;
        const endpoint = directoryPicker.dataset.directoryPickerUrl;
        const currentLabel = directoryPicker.querySelector('[data-directory-picker-current]');
        const list = directoryPicker.querySelector('[data-directory-picker-list]');
        const parentButton = directoryPicker.querySelector('[data-directory-picker-parent]');
        const selectButton = directoryPicker.querySelector('[data-directory-picker-select]');
        let activeInput = null;
        let currentPath = '';
        let parentPath = null;

        const showDirectoryPickerMessage = (message, type = 'muted') => {
            list.innerHTML = '';

            const item = document.createElement('div');
            item.className = `list-group-item text-${type} small`;
            item.textContent = message;
            list.appendChild(item);
        };

        const loadDirectories = async (path = '') => {
            showDirectoryPickerMessage('フォルダを読み込んでいます。');

            let data;

            try {
                const response = await fetch(`${endpoint}?path=${encodeURIComponent(path)}`, {
                    headers: {
                        Accept: 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                data = await response.json();
            } catch (error) {
                currentLabel.textContent = 'storage/app';
                parentButton.disabled = true;
                showDirectoryPickerMessage('フォルダ一覧を取得できませんでした。ページを再読み込みして再度お試しください。', 'danger');

                return;
            }

            currentPath = data.current?.path ?? '';
            parentPath = data.parent ?? null;
            currentLabel.textContent = data.current?.label ?? 'storage/app';
            parentButton.disabled = parentPath === null;
            list.innerHTML = '';

            if ((data.directories ?? []).length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted small';
                empty.textContent = '下位フォルダはありません。';
                list.appendChild(empty);

                return;
            }

            data.directories.forEach((directory) => {
                const button = document.createElement('button');
                button.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
                button.type = 'button';
                button.innerHTML = '<i class="bi bi-folder" aria-hidden="true"></i>';
                button.append(document.createTextNode(directory.name));
                button.addEventListener('click', () => loadDirectories(directory.path));
                list.appendChild(button);
            });
        };

        document.querySelectorAll('.js-directory-picker').forEach((button) => {
            button.addEventListener('click', async () => {
                activeInput = document.querySelector(button.dataset.directoryPickerTarget);
                modal.show();
                await loadDirectories(activeInput?.value ?? '');
            });
        });

        parentButton.addEventListener('click', () => {
            if (parentPath !== null) {
                loadDirectories(parentPath);
            }
        });

        selectButton.addEventListener('click', () => {
            if (activeInput) {
                activeInput.value = currentPath;
                activeInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            modal.hide();
        });

        if (!window.bootstrap) {
            directoryPicker.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => {
                button.addEventListener('click', () => modal.hide());
            });
        }
    }
})();
