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
})();
