<?php

declare(strict_types=1);

namespace Drupal\reports;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reports\Plugin\Report\ReportInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Appends rows to a generated report's sheet files.
 *
 * Each sheet is a CSV file that starts with the sheet's column labels.
 */
class ReportWriter {

  /**
   * Open file handles, keyed by sheet ID.
   *
   * @var array<string, resource>
   */
  protected array $handles = [];

  /**
   * The headers of the report's sheets, keyed by sheet ID.
   *
   * @var array<string, array>
   */
  protected array $headers = [];

  /**
   * Constructs a ReportWriter.
   *
   * @param string $directory
   *   The directory to write the sheet files to. It must exist.
   * @param \Drupal\reports\Plugin\Report\ReportInterface $report
   *   The report.
   */
  public function __construct(
    protected readonly string $directory,
    ReportInterface $report,
  ) {
    foreach (array_keys($report->getSheets()) as $sheet) {
      $this->headers[$sheet] = $report->getHeader((string) $sheet);
    }
  }

  /**
   * Closes the files.
   */
  public function __destruct() {
    array_map('fclose', $this->handles);
  }

  /**
   * Adds a row to a sheet.
   *
   * @param array<string, mixed> $row
   *   The row, keyed by column key. Missing columns are left empty, and
   *   unknown columns are ignored.
   * @param string $sheet
   *   (optional) The sheet ID. Defaults to the default sheet.
   *
   * @throws \InvalidArgumentException
   *   If the report has no such sheet.
   */
  public function addRow(array $row, string $sheet = ReportInterface::SHEET_DEFAULT): void {
    if (!isset($this->headers[$sheet])) {
      throw new \InvalidArgumentException("The report has no sheet named '$sheet'.");
    }
    if (!isset($this->handles[$sheet])) {
      $path = "{$this->directory}/{$sheet}.csv";
      $exists = file_exists($path);
      $this->handles[$sheet] = fopen($path, 'a');
      if (!$exists) {
        static::writeCsvRow($this->handles[$sheet], static::getLabels($this->headers[$sheet]));
      }
    }
    static::writeCsvRow($this->handles[$sheet], static::normalizeRow($this->headers[$sheet], $row));
  }

  /**
   * Creates the files of sheets that have no rows, so they have a header.
   */
  public function createEmptySheets(): void {
    foreach ($this->headers as $sheet => $header) {
      if (!file_exists("{$this->directory}/{$sheet}.csv")) {
        $handle = fopen("{$this->directory}/{$sheet}.csv", 'w');
        static::writeCsvRow($handle, static::getLabels($header));
        fclose($handle);
      }
    }
  }

  /**
   * Writes a row to a CSV file.
   *
   * @param resource $handle
   *   The file handle.
   * @param array $row
   *   The row's cells.
   */
  public static function writeCsvRow($handle, array $row): void {
    fputcsv($handle, array_map(static::normalizeCell(...), $row), escape: '');
  }

  /**
   * Gets the column labels of a header.
   *
   * @param array<string, string|\Stringable|array{label: string|\Stringable, filter?: string|false}> $header
   *   The header, as in ReportInterface::getHeader().
   *
   * @return array<string, string|\Stringable>
   *   The labels, keyed by column key.
   */
  public static function getLabels(array $header): array {
    return array_map(fn ($column) => is_array($column) ? $column['label'] : $column, $header);
  }

  /**
   * Orders a row by the header's columns and converts its cells to strings.
   *
   * @param array<string, mixed> $header
   *   Column labels keyed by column key.
   * @param array<string, mixed> $row
   *   The row, keyed by column key.
   *
   * @return list<string>
   *   The cells, in the header's order.
   */
  public static function normalizeRow(array $header, array $row): array {
    return array_map(fn ($key) => static::normalizeCell($row[$key] ?? NULL), array_keys($header));
  }

  /**
   * Converts a row of plain text cells to a spreadsheet row, with links.
   *
   * As on the report page, cells of columns with a 'url' column link to that
   * column's URL, and cells with an absolute URL link to it. Links are
   * HYPERLINK() formulas, since OpenSpout has no hyperlink cells; Excel
   * limits their URLs to 255 characters, so longer ones stay plain text.
   *
   * @param array<string, mixed> $header
   *   The sheet's columns, as in ReportInterface::getHeader().
   * @param list<string> $values
   *   The cells as plain text, in the header's order, e.g. a CSV row.
   *
   * @return \OpenSpout\Common\Entity\Row
   *   The row.
   */
  public static function toSpreadsheetRow(array $header, array $values): Row {
    $keys = array_keys($header);
    $row = array_combine($keys, array_pad(array_slice(array_values($values), 0, count($keys)), count($keys), ''));
    $cells = [];
    foreach ($header as $key => $column) {
      $text = (string) $row[$key];
      $url = is_array($column) && isset($column['url']) ? (string) ($row[$column['url']] ?? '') : $text;
      if ($text === '' || !preg_match('#^https?://#', $url) || strlen($url) > 255 || !UrlHelper::isValid($url, TRUE)) {
        // Lists (array cells) have one item per line, which Excel only shows
        // in cells that wrap their text.
        $cells[] = Cell::fromValue($text, str_contains($text, "\n") ? (new Style())->withShouldWrapText(TRUE) : NULL);
        continue;
      }
      $quote = fn (string $value) => '"' . str_replace('"', '""', $value) . '"';
      $cells[] = new FormulaCell('=HYPERLINK(' . $quote($url) . ($url === $text ? '' : ',' . $quote($text)) . ')', $text);
    }
    return new Row($cells);
  }

  /**
   * Converts a cell to plain text.
   *
   * URLs become absolute URLs. Links keep only their text: use a separate
   * \Drupal\Core\Url column when the URL is needed in files and Drush output.
   * Lists (arrays) become one item per line.
   *
   * @param mixed $cell
   *   The cell.
   *
   * @return string
   *   The plain text.
   */
  public static function normalizeCell(mixed $cell): string {
    return match (TRUE) {
      $cell === NULL => '',
      is_array($cell) => implode("\n", array_map(static::normalizeCell(...), $cell)),
      is_bool($cell) => $cell ? '1' : '0',
      $cell instanceof Url => $cell->setAbsolute()->toString(),
      $cell instanceof Link => PlainTextOutput::renderFromHtml((string) $cell->getText()),
      $cell instanceof MarkupInterface => PlainTextOutput::renderFromHtml((string) $cell),
      $cell instanceof \UnitEnum => $cell->name,
      default => (string) $cell,
    };
  }

}
