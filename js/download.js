/**
 * @file
 * Downloads a download-only report once it has been generated.
 */

((Drupal, drupalSettings) => {
  Drupal.behaviors.reportsDownload = {
    attach() {
      const url = drupalSettings.reports?.download;
      if (!url) {
        return;
      }
      delete drupalSettings.reports.download;
      // Remove the download parameter, so reloading doesn't download again.
      const current = new URL(window.location.href);
      current.searchParams.delete('download');
      window.history.replaceState(null, '', current.toString());
      window.location.href = url;
    },
  };
})(Drupal, drupalSettings);
