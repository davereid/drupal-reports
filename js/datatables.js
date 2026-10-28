/**
 * @file
 * Starts DataTables on tables with the data-datatables attribute.
 *
 * Options come from data attributes:
 * - data-datatables-page-length: The number of rows per page.
 *
 * Other scripts get the instance from the datatables:init event, dispatched
 * on the table (and bubbling) with the DataTables API instance in
 * event.detail.dataTable. Listen for it on the document when the script
 * loads, since tables can start before other behaviors attach.
 *
 * Add-ons, such as the exposed form (datatables.exposed-form.js), also listen
 * for it and add what they build to event.detail, e.g.
 * event.detail.exposedForm (null without one). Listeners run in the order
 * they were added, so scripts that read an add-on's detail must have its
 * library as a dependency.
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
        table.dispatchEvent(
          new CustomEvent('datatables:init', {
            bubbles: true,
            detail: { dataTable, exposedForm: null },
          }),
        );
      });
    },
  };
})(Drupal, once, window.DataTable);
