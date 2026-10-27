/**
 * @file
 * Moves a DataTables table's search and column filters into an exposed form.
 *
 * Tables opt in with the data-datatables-exposed-form attribute. Columns whose
 * heading has data-datatables-exposed-filter="list" get a select of their
 * values, and "search" a text field. The form uses the markup and classes of
 * a Views exposed form, so admin themes style it the same way, and replaces
 * DataTables' own search box with a Search field.
 *
 * Filters apply when the form is submitted, or as they change on tables with
 * the data-datatables-exposed-form-autosubmit attribute.
 */

((Drupal) => {
  /**
   * Builds a labeled form item for a filter.
   *
   * @param {string} label
   *   The label.
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
    element.id = `datatables-exposed-form-${Math.random().toString(36).slice(2)}`;
    labelElement.htmlFor = element.id;
    item.append(labelElement, element);
    return item;
  }

  /**
   * Builds a text field.
   *
   * @return {HTMLInputElement}
   *   The field.
   */
  function buildSearch() {
    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-search form-element form-element--type-search';
    return input;
  }

  /**
   * Builds the filter of a column.
   *
   * @param {DataTable.Api} column
   *   The column.
   * @param {string} type
   *   'list' or 'search'.
   *
   * @return {HTMLElement|null}
   *   The filter's form item, or null for a list of a column without values.
   */
  function buildFilter(column, type) {
    const label = column.header().textContent.trim();
    if (type === 'search') {
      return buildItem(label, buildSearch(), 'search');
    }

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
    // A list with only "- Any -" can't filter anything.
    if (!values.length) {
      return null;
    }
    const select = document.createElement('select');
    select.className = 'form-select form-element form-element--type-select';
    select.append(new Option(Drupal.t('- Any -'), ''));
    values.forEach((value) => select.append(new Option(value, value)));
    return buildItem(label, select, 'select');
  }

  Drupal.dataTablesExposedForm = {
    /**
     * Adds an exposed form to a table that opts in.
     *
     * @param {DataTable.Api} dataTable
     *   The table.
     *
     * @return {{form: HTMLFormElement, actions: HTMLElement, fields: Array<{column: DataTable.Api, field: HTMLElement, exact: boolean}>}|null}
     *   The form, its actions, to add buttons to, and its fields: the
     *   table's search (with the table as its column), then each column
     *   filter. Null if the table doesn't opt in.
     */
    attach(dataTable) {
      const table = dataTable.table().node();
      if (!table.hasAttribute('data-datatables-exposed-form')) {
        return null;
      }
      const autosubmit = table.hasAttribute(
        'data-datatables-exposed-form-autosubmit',
      );

      // As in Claro's views-exposed-form.html.twig, the items and actions
      // are direct children of the form.
      const form = document.createElement('form');
      form.className = 'views-exposed-form datatables-exposed-form';
      const fields = [];

      // The table's search, across every column, replaces DataTables' own.
      const search = buildSearch();
      form.append(buildItem(Drupal.t('Search'), search, 'search'));
      fields.push({ column: dataTable, field: search, exact: false });
      dataTable.table().container().querySelector('.dt-search')?.remove();

      dataTable.columns().every(function addFilter(index) {
        const type = this.header().dataset.datatablesExposedFilter;
        if (type === 'list' || type === 'search') {
          const item = buildFilter(this, type);
          if (item) {
            fields.push({
              column: this,
              field: item.querySelector('select, input'),
              // Exact for lists, so "Published" doesn't also match
              // "Unpublished".
              exact: type === 'list',
            });
            form.append(item);
          }
        }
        return index;
      });
      // As Claro's preprocessViewsExposedForm() does, so the actions sit
      // next to the last filter.
      form.lastElementChild?.classList.add(
        'views-exposed-form__item--preceding-actions',
      );

      const submit = document.createElement('button');
      submit.type = 'submit';
      submit.className = 'button button--primary js-form-submit form-submit';
      submit.textContent = Drupal.t('Filter');
      submit.hidden = autosubmit;
      const reset = document.createElement('button');
      reset.type = 'button';
      reset.className = 'button js-form-submit form-submit';
      reset.textContent = Drupal.t('Reset');
      reset.hidden = true;
      const updateReset = () => {
        reset.hidden = !fields.some(({ column }) => column.search() !== '');
      };

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        fields.forEach(({ column, field, exact }) => {
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
      if (autosubmit) {
        let timer;
        form.addEventListener('change', () => form.requestSubmit());
        // Text fields submit once typing pauses.
        form.addEventListener('input', (event) => {
          if (event.target.type === 'search') {
            clearTimeout(timer);
            timer = setTimeout(() => form.requestSubmit(), 500);
          }
        });
      }

      const actions = document.createElement('div');
      actions.className =
        'form-actions js-form-wrapper form-wrapper views-exposed-form__item views-exposed-form__item--actions';
      actions.append(submit, reset);
      form.append(actions);
      const container = dataTable.table().container();
      container.parentNode.insertBefore(form, container);
      return { form, actions, fields };
    },
  };
})(Drupal);
