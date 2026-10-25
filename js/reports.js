/**
 * @file
 * Displays report tables with DataTables, with column filters below the
 * headings, and a summary of the active filters above the table.
 */

((Drupal, once, DataTable) => {
  // ColumnControl content for each data-reports-filter value: a search box,
  // or a dropdown with a list of the column's values.
  const filters = {
    search: ['search'],
    list: [['searchList']],
    none: [],
  };

  /**
   * Gets the values selected in each column's filter.
   *
   * @param {DataTable.Api} dataTable
   *   The table.
   *
   * @return {Array<{index: number, label: string, values: string[]}>}
   *   The columns with a filter, and their values.
   */
  function getActiveFilters(dataTable) {
    const state = dataTable.state() || {};
    const active = [];
    dataTable.columns().every(function addFilter(index) {
      const control = (state.columnControl || {})[index] || {};
      // List filters keep their selected values; search filters their text.
      const values = [].concat(control.searchList || []);
      const search = this.search();
      if (search) {
        values.push(search);
      }
      if (values.length) {
        active.push({
          index,
          label: this.header().textContent.trim(),
          values,
        });
      }
    });
    return active;
  }

  /**
   * Updates the summary of active filters.
   *
   * @param {DataTable.Api} dataTable
   *   The table.
   * @param {HTMLElement} summary
   *   The summary element.
   */
  function updateSummary(dataTable, summary) {
    const active = getActiveFilters(dataTable);
    summary.replaceChildren();
    summary.hidden = !active.length;
    if (!active.length) {
      return;
    }
    summary.append(
      `${Drupal.t('Filtered by')}: ${active
        .map(({ label, values }) => `${label}: ${values.join(', ')}`)
        .join(' · ')} `,
    );
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'link';
    clear.textContent = Drupal.t('Clear filters');
    clear.addEventListener('click', () => {
      active.forEach(({ index }) => {
        dataTable.column(index).columnControl.searchClear();
      });
      dataTable.draw();
    });
    summary.append(clear);
  }

  Drupal.behaviors.reportsDataTable = {
    attach(context) {
      once('reports-datatable', 'table.reports-table', context).forEach(
        (table) => {
          const columns = Array.from(table.querySelectorAll('thead th')).map(
            (th) => ({
              columnControl: [
                {
                  target: 1,
                  content: filters[th.dataset.reportsFilter] || filters.none,
                },
              ],
            }),
          );
          const dataTable = new DataTable(table, { pageLength: 50, columns });

          const summary = document.createElement('p');
          summary.className = 'reports-filters';
          summary.setAttribute('aria-live', 'polite');
          summary.hidden = true;
          table.parentNode.insertBefore(summary, table);
          dataTable.on('draw', () => updateSummary(dataTable, summary));
        },
      );
    },
  };
})(Drupal, once, DataTable);
