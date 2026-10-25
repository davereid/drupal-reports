<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

/**
 * Base class for reports shown like core's status report.
 *
 * Use with type: 'status' in the Report attribute. Rows have the keys of
 * getHeader(): 'severity' is a
 * \Drupal\Core\Extension\Requirement\RequirementSeverity.
 */
abstract class StatusReportBase extends ReportBase implements RealtimeReportInterface {

  /**
   * {@inheritdoc}
   */
  public function getHeader(): array {
    return [
      'key' => $this->t('Key'),
      'label' => $this->t('Label'),
      'status' => $this->t('Status'),
      'severity' => $this->t('Severity'),
      'description' => $this->t('Description'),
    ];
  }

}
