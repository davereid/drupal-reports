<?php

declare(strict_types=1);

namespace Drupal\reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\reports\Exception\ReportGeneratingException;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
use Drupal\reports\ReportGenerator;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures a report, and reloads it with the configuration in the URL.
 *
 * Generated reports that haven't been generated with the new configuration
 * are generated in the same step.
 */
class ReportConfigForm extends FormBase {

  /**
   * The report generator.
   */
  protected ReportGenerator $reportGenerator;

  /**
   * The report storage.
   */
  protected ReportStorage $reportStorage;

  /**
   * The report plugin manager.
   */
  protected ReportManager $reportManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->reportGenerator = $container->get(ReportGenerator::class);
    $instance->reportStorage = $container->get(ReportStorage::class);
    $instance->reportManager = $container->get(ReportManager::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reports_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ReportInterface $report = NULL, string $sheet = 'default'): array {
    assert($report instanceof PluginFormInterface);
    $form_state->set('report', $report);
    $form['config'] = [
      '#type' => 'details',
      '#title' => $this->t('Options'),
      // Collapsed once there's a generated report to look at.
      '#open' => !$report instanceof GeneratedReportInterface || !$this->reportStorage->hasFiles($report),
      '#tree' => TRUE,
    ];
    $form['config'] = $report->buildConfigurationForm($form['config'], SubformState::createForSubform($form['config'], $form, $form_state));
    // The actions are outside the collapsible options, so they're always
    // visible.
    $form['actions'] = ['#type' => 'actions'];
    $download_only = $this->reportStorage->isDownloadOnly($report);
    $has_files = $report instanceof GeneratedReportInterface && $this->reportStorage->hasFiles($report);
    $form['actions']['submit'] = [
      '#type' => 'submit',
      // Download-only reports can't be viewed: the button generates and
      // downloads them, and the download link replaces it once they exist.
      '#value' => $download_only ? $this->t('Download report') : $this->t('View report'),
      '#button_type' => 'primary',
      '#access' => !($download_only && $has_files),
    ];
    if ($report instanceof GeneratedReportInterface) {
      $form['actions']['regenerate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Regenerate report'),
        '#submit' => ['::submitForm', '::regenerate'],
        // Without files, View report generates the report.
        '#access' => $this->reportManager->isUsable($report) && $this->reportStorage->hasFiles($report),
      ];
    }
    // Downloads of the report with its current options.
    $generated = $report instanceof GeneratedReportInterface;
    $form['actions']['download'] = [
      '#type' => 'link',
      '#title' => $this->t('Download CSV'),
      '#url' => $report->toUrl('csv', ['query' => ['sheet' => $sheet]]),
      '#attributes' => ['class' => ['button']],
      '#access' => !$this->reportStorage->isDownloadOnly($report) && (!$generated || $this->reportStorage->getFile($report, $sheet) !== NULL),
    ];
    $form['actions']['download_xlsx'] = [
      '#type' => 'link',
      '#title' => $this->t('Download Excel file'),
      '#url' => $report->toUrl('xlsx'),
      '#attributes' => ['class' => ['button']],
      // Stored spreadsheets are sent as they are; other reports are converted.
      '#access' => !$generated || $has_files,
    ];
    if ($download_only) {
      $form['actions']['download_xlsx']['#attributes']['class'][] = 'button--primary';
      $form['actions']['download_xlsx']['#weight'] = -10;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $form_state->get('report')->validateConfigurationForm($form['config'], SubformState::createForSubform($form['config'], $form, $form_state));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $report = $form_state->get('report');
    $report->submitConfigurationForm($form['config'], SubformState::createForSubform($form['config'], $form, $form_state));
    $this->setReportRedirect($form_state, FALSE);
    // Form API runs the batch, then follows the redirect to the report.
    if ($report instanceof GeneratedReportInterface && $this->reportManager->isUsable($report) && !$this->reportStorage->hasFiles($report)) {
      $form_state->set('generating', TRUE);
      $this->setBatch($form_state);
    }
  }

  /**
   * Redirects to the report with its configuration.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param bool $generating
   *   TRUE if the report is being generated: download-only reports are then
   *   downloaded when the page loads.
   */
  protected function setReportRedirect(FormStateInterface $form_state, bool $generating): void {
    $report = $form_state->get('report');
    $query = $generating && $this->reportStorage->isDownloadOnly($report) ? ['download' => 1] : [];
    $form_state->setRedirectUrl($report->toUrl('canonical', ['query' => $query]));
  }

  /**
   * Submit callback: regenerates the report with the submitted configuration.
   */
  public function regenerate(array &$form, FormStateInterface $form_state): void {
    // submitForm() already started a batch if there was no file.
    if (!$form_state->get('generating')) {
      $this->setBatch($form_state);
    }
  }

  /**
   * Starts generating the report, unless it's already being generated.
   */
  protected function setBatch(FormStateInterface $form_state): void {
    try {
      batch_set($this->reportGenerator->buildBatch($form_state->get('report')));
      $this->setReportRedirect($form_state, TRUE);
    }
    catch (ReportGeneratingException $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
