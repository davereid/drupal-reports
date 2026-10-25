<?php

declare(strict_types=1);

namespace Drupal\reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
use Drupal\reports\ReportGenerator;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->reportGenerator = $container->get(ReportGenerator::class);
    $instance->reportStorage = $container->get(ReportStorage::class);
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
  public function buildForm(array $form, FormStateInterface $form_state, ?ReportInterface $report = NULL): array {
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
    // The buttons are inside the details element, so they're hidden with it.
    $form['config']['actions'] = ['#type' => 'actions', '#weight' => 100];
    $form['config']['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('View report'), '#button_type' => 'primary'];
    if ($report instanceof GeneratedReportInterface) {
      $form['config']['actions']['regenerate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Regenerate report'),
        '#submit' => ['::submitForm', '::regenerate'],
        // Without files, View report generates the report.
        '#access' => $this->reportStorage->isAvailable() && $this->reportStorage->hasFiles($report),
      ];
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
    $form_state->setRedirect('reports.report', ['report' => $report->getPluginId()], ['query' => ['config' => $report->getConfiguration()]]);
    // Form API runs the batch, then follows the redirect to the report.
    if ($report instanceof GeneratedReportInterface && $this->reportStorage->isAvailable() && !$this->reportStorage->hasFiles($report)) {
      $form_state->set('generating', TRUE);
      batch_set($this->reportGenerator->buildBatch($report));
    }
  }

  /**
   * Submit callback: regenerates the report with the submitted configuration.
   */
  public function regenerate(array &$form, FormStateInterface $form_state): void {
    // submitForm() already started a batch if there was no file.
    if (!$form_state->get('generating')) {
      batch_set($this->reportGenerator->buildBatch($form_state->get('report')));
    }
  }

}
