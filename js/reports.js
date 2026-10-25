/**
 * @file
 * Displays report tables with DataTables, with filters above the table.
 *
 * Columns whose heading has data-reports-filter="list" get a select of their
 * values, and "search" a text field. They use the markup and classes of a
 * Views exposed form, so admin themes style them the same way.
 */

((Drupal, once, DataTable) => {
  /**
   * Builds a labeled form item for a filter.
   *
   * @param {string} label
   *   The column label.
   * @param {HTMLElement} element
   *   The select or input.
   * @param {string} type
   *   The form element type: 'select' or 'search'.
   *
   * @return {HTMLElement}
   *   The form item.
   */
  function buildItem(label, element, type) {
    const item = document.createElement('div');
    item.className = `form-item js-form-item form-type-${type} js-form-type-${type} views-exposed-form__item`;
    const labelElement = document.createElement('label');
    labelElement.className = 'form-item__label';
    labelElement.textContent = label;
    element.id = `reports-filter-${Math.random().toString(36).slice(2)}`;
    labelElement.htmlFor = element.id;
    item.append(labelElement, element);
    return item;
  }

  /**
   * Builds the filter of a column.
   *
   * @param {DataTable.Api} column
   *   The column.
   * @param {string} type
   *   'list' or 'search'.
   *
   * @return {HTMLElement}
   *   The filter's form item.
   */
  function buildFilter(column, type) {
    const label = column.header().textContent.trim();
    if (type === 'search') {
      const input = document.createElement('input');
      input.type = 'search';
      input.className = 'form-search form-element form-element--type-search';
      return buildItem(label, input, 'search');
    }

    const select = document.createElement('select');
    select.className = 'form-select form-element form-element--type-select';
    select.append(new Option(Drupal.t('- Any -'), ''));
    // The cells' text, as shown, rather than their HTML.
    const values = [
      ...new Set(
        column
          .nodes()
          .toArray()
          .map((cell) => cell.textContent.trim())
          .filter((value) => value !== ''),
      ),
    ].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    values.forEach((value) => select.append(new Option(value, value)));
    return buildItem(label, select, 'select');
  }

  /**
   * Downloads the rows shown, filtered and sorted, as a CSV file.
   *
   * Cells are exported as their text: links keep their text, not their URL,
   * and columns hidden on the page aren't included.
   *
   * @param {DataTable.Api} dataTable
   *   The table.
   * @param {string} filename
   *   The file name.
   */
  function downloadFiltered(dataTable, filename) {
    const escape = (value) => `"${value.replace(/"/g, '""')}"`;
    const lines = [
      dataTable
        .columns()
        .header()
        .toArray()
        .map((th) => escape(th.textContent.trim())),
    ];
    dataTable
      .rows({ search: 'applied', order: 'applied' })
      .nodes()
      .toArray()
      .forEach((tr) => {
        lines.push(
          Array.from(tr.cells).map((td) => escape(td.textContent.trim())),
        );
      });
    const csv = lines.map((line) => line.join(',')).join('\r\n');
    const link = document.createElement('a');
    // A byte order mark, so spreadsheet programs read the file as UTF-8.
    link.href = URL.createObjectURL(
      new Blob(['\ufeff', csv], { type: 'text/csv;charset=utf-8' }),
    );
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  Drupal.behaviors.reportsDataTable = {
    attach(context) {
      once('reports-datatable', 'table.reports-table', context).forEach(
        (table) => {
          const dataTable = new DataTable(table, { pageLength: 50 });

          // A form like Views' exposed forms, so it's styled the same way:
          // the items and actions are direct children, as in Claro's
          // views-exposed-form.html.twig.
          const bar = document.createElement('form');
          bar.className = 'views-exposed-form reports-filters';
          const submit = document.createElement('button');
          submit.type = 'submit';
          submit.className =
            'button button--primary js-form-submit form-submit';
          submit.textContent = Drupal.t('Filter');
          const reset = document.createElement('button');
          reset.type = 'button';
          reset.className = 'button js-form-submit form-submit';
          reset.textContent = Drupal.t('Reset');
          reset.hidden = true;

          const download = document.createElement('button');
          download.type = 'button';
          download.className = 'button js-form-submit form-submit';
          download.textContent = Drupal.t('Download filtered results (CSV)');
          download.hidden = true;
          download.addEventListener('click', () => {
            // The report, then each applied filter and the table's search,
            // e.g. absolute_urls-state-unpublished.csv.
            const slug = (value) =>
              value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '');
            const parts = [table.dataset.reportsFilename || 'report'];
            fields.forEach(({ column }) => {
              if (column.search()) {
                parts.push(
                  slug(column.header().textContent.trim()),
                  slug(column.search()),
                );
              }
            });
            if (dataTable.search()) {
              parts.push('search', slug(dataTable.search()));
            }
            const filename = parts.filter(Boolean).join('-').slice(0, 150);
            downloadFiltered(dataTable, `${filename}.csv`);
          });

          // Each filter's column and field.
          const fields = [];
          const updateReset = () => {
            reset.hidden = !fields.some(
              ({ column }) => column.search() !== '',
            );
          };
          // The filtered download is offered while the filters or the table's
          // search hide rows.
          const updateDownload = () => {
            download.hidden =
              dataTable.rows({ search: 'applied' }).count() ===
              dataTable.rows().count();
            bar.hidden = !fields.length && download.hidden;
          };
          dataTable.on('draw', updateDownload);
          dataTable.columns().every(function addFilter(index) {
            const type = this.header().dataset.reportsFilter;
            if (type === 'list' || type === 'search') {
              const item = buildFilter(this, type);
              fields.push({
                column: this,
                field: item.querySelector('select, input'),
                exact: type === 'list',
              });
              bar.append(item);
            }
            return index;
          });
          // As Claro's preprocessViewsExposedForm() does, so the actions
          // sit next to the last filter.
          bar.lastElementChild?.classList.add(
            'views-exposed-form__item--preceding-actions',
          );

          // Filters apply when the form is submitted, as in Views.
          bar.addEventListener('submit', (event) => {
            event.preventDefault();
            fields.forEach(({ column, field, exact }) => {
              // Exact for lists, so "Published" doesn't also match
              // "Unpublished".
              column.search(field.value, { exact });
            });
            dataTable.draw();
            updateReset();
          });
          reset.addEventListener('click', () => {
            fields.forEach(({ column, field }) => {
              field.value = '';
              column.search('');
            });
            dataTable.draw();
            updateReset();
          });
          const actions = document.createElement('div');
          actions.className =
            'form-actions js-form-wrapper form-wrapper views-exposed-form__item views-exposed-form__item--actions';
          if (fields.length) {
            actions.append(submit, reset);
          }
          actions.append(download);
          bar.append(actions);
          const container = dataTable.table().container();
          container.parentNode.insertBefore(bar, container);
          updateDownload();
        },
      );
    },
  };
})(Drupal, once, DataTable);
