/**
 * @file
 * Starts DataTables on tables with the data-datatables attribute.
 *
 * Options come from data attributes:
 * - data-datatables-page-length: The number of rows per page.
 * - data-datatables-exposed-form (and -autosubmit): See
 *   datatables.exposed-form.js.
 *
 * Other scripts get the instance from the datatables:init event, dispatched
 * on the table (and bubbling) with the DataTables API instance in
 * event.detail.dataTable, and the exposed form, if any, in
 * event.detail.exposedForm. Listen for it on the document when the script
 * loads, since tables can start before other behaviors attach.
 */

((Drupal, once, DataTable) => {
  Drupal.behaviors.dataTables = {
    attach(context) {
      once('datatables', 'table[data-datatables]', context).forEach((table) => {
        const options = {};
        const pageLength = parseInt(table.dataset.datatablesPageLength, 10);
        if (pageLength > 0) {
          options.pageLength = pageLength;
        }
        const dataTable = new DataTable(table, options);
        const exposedForm = Drupal.dataTablesExposedForm.attach(dataTable);
        table.dispatchEvent(
          new CustomEvent('datatables:init', {
            bubbles: true,
            detail: { dataTable, exposedForm },
          }),
        );
      });
    },
  };
})(Drupal, once, window.DataTable);
