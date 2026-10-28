/**
 * @file
 * Adds the filtered results download to report tables.
 *
 * Report tables are started by datatables.js, with the DataTables exposed
 * form (datatables.exposed-form.js) for their search and filters. This adds a
 * button to download the filtered results.
 */

((Drupal) => {
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

  // Added when DataTables starts on a report table (datatables.js).
  document.addEventListener('datatables:init', (event) => {
    const table = event.target;
    if (!table.matches('table.reports-table')) {
      return;
    }
    const { dataTable, exposedForm } = event.detail;

    const download = Drupal.theme(
      'dataTablesButton',
      Drupal.t('Download filtered results (CSV)'),
    );
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
      (exposedForm?.fields ?? []).forEach(({ column }) => {
        // The table's search is added last.
        if (column !== dataTable && column.search()) {
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

    // The filtered download is offered while the search or filters
    // hide rows. Without an exposed form, it goes above the table.
    if (exposedForm) {
      exposedForm.actions.append(download);
    } else {
      const container = dataTable.table().container();
      container.parentNode.insertBefore(download, container);
    }
    const updateDownload = () => {
      download.hidden =
        dataTable.rows({ search: 'applied' }).count() ===
        dataTable.rows().count();
    };
    dataTable.on('draw', updateDownload);
    updateDownload();
  });
})(Drupal);
