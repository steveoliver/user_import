<?php

/**
 * @file
 * Contains \Drupal\user_import\Form\UserImportForm.
 */

namespace Drupal\user_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\RoleInterface;
use Drupal\user_import\Controller\UserImportController;

class UserImportForm extends FormBase {

  /**
   * Implements \Drupal\Core\Form\FormInterface::getFormID().
   */
  public function getFormID() {
    return 'user_import_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [];
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface::buildForm().
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array $form
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $roles = user_role_names();
    unset($roles['anonymous']);
    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => $roles
    ];
    // Special handling for the inevitable "Authenticated user" role.
    $form['roles'][RoleInterface::AUTHENTICATED_ID] = array(
      '#default_value' => RoleInterface::AUTHENTICATED_ID,
      '#disabled' => TRUE,
    );
    $form['notify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify user(s) of new account'),
      '#default_value' => 1
    ];
    $form['file'] = [
      '#type' => 'file',
      '#title' => 'CSV file upload',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv']
      ]
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Import users'),
      '#button_type' => 'primary',
    );

    // By default, render the form using theme_system_config_form().
    $form['#theme'] = 'system_config_form';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate options.
    $roles = $form_state->getValue(['roles']);
    $roles_selected = array_filter($roles, function($item) {
      return ($item);
    });
    if (empty($roles_selected)) {
      $form_state->setErrorByName('roles', $this->t('Please select at least one role to apply to the imported user(s).'));
    }
    // Validate file.
    $this->file = file_save_upload('file', $form['file']['#upload_validators']);
    if (!$this->file[0]) {
      $form_state->setErrorByName('file');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file = $this->file[0];
    $roles = $form_state->getValue(['roles']);
    $config = [
      'roles' => array_filter($roles, function ($item) {
        return ($item);
      }),
      'notify' => $form_state->getValue(['notify'])
    ];

    $batch = [
      'title' => t('Importing'),
      'operations' => [
        [[$this, 'processUserImportBatch'], [$file, $config]]
      ],
      'finished' => [$this, 'finishUserImportBatch']
    ];
    batch_set($batch);
  }

  /**
   * Processes a user import batch.
   *
   * @param $file
   * @param $config
   * @param $context
   */
  public function processUserImportBatch($file, $config, &$context) {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;

      // Prepare the rows of data to process.
      $context['sandbox']['rows'] = [];
      $handle = fopen($file->destination, 'r');
      while ($row = fgetcsv($handle)) {
        $context['sandbox']['rows'][] = $row;
      }
      $context['sandbox']['max'] = count($context['sandbox']['rows']);
    }
    if (empty($context['results'])) {
      $context['results'] = [
        'added' => [],
        'created' => []
      ];
    }

    $limit = 100;
    $current_batch = array_slice($context['sandbox']['rows'], $context['sandbox']['progress'], $limit);

    foreach ($current_batch as $row) {
      if ($values = UserImportController::prepareUploadRow($row, $config)) {
        if (!empty($values['date'])) {
          // At least one week future date? Add to waitlist.
          if (!UserImportController::shouldCreateUserNow($values)) {
            if (UserImportController::addUserToWaitlist($values)) {
              $context['results']['added'][] = $values['first'] . ' ' . $values['last'] . ' added to waitlist.';
            }
          }
          // Today or previous date? Create user now with training date.
          else {
            if (UserImportController::createUser($values)) {
              $context['results']['created'][] = $values['first'] . ' ' . $values['last'] . ' created.';
            }
          }
        }
        else {
          if (UserImportController::createUser($values)) {
            $context['results']['created'][] = $values['first'] . ' ' . $values['last'] . ' created';
          }
        }
      }
      $context['message'] = count($context['results']['created']) . ' users created, ' . count($context['results']['added']) . ' users waitlisted.';
      $context['sandbox']['progress']++;
    }
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Finishes a User Import batch.
   *
   * @param $success
   * @param $results
   * @param $operations
   */
  public function finishUserImportBatch($success, $results, $operations) {
    if ($success) {
      if (!empty($results['created']) || !empty($results['added'])) {
        if (!empty($results['created'])) {
          drupal_set_message(\Drupal::translation()->formatPlural(count($results['created']), 'One user created.', '@count users created.'));
        }
        if (!empty($results['added'])) {
          drupal_set_message(\Drupal::translation()->formatPlural(count($results['added']), 'One user waitlisted.', '@count users waitlisted.'));
        }
      }
    }
    else {
      drupal_set_message(t('Finished with an error.'));
    }
  }

}
