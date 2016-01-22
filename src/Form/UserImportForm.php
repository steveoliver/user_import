<?php

/**
 * @file
 * Contains \Drupal\user_import\Form\UserImportForm.
 */

namespace Drupal\user_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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
    $form['#tree'] = TRUE;
    $form['config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Options')
    ];
    $roles = user_role_names();
    unset($roles['anonymous']);
    $form['config']['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => $roles
    ];
    // Special handling for the inevitable "Authenticated user" role.
    $form['config']['roles'][RoleInterface::AUTHENTICATED_ID] = array(
      '#default_value' => TRUE,
      '#disabled' => TRUE,
    );
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
    $roles = $form_state->getValue(['config', 'roles']);
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
    $roles = $form_state->getValue(['config', 'roles']);
    $config = [
      'roles' => array_filter($roles, function($item) {
        return ($item);
      })
    ];
    if ($created = UserImportController::processUpload($file, $config)) {
      drupal_set_message(t('Successfully imported @count users.', ['@count' => count($created)]));
    }
    else {
      drupal_set_message(t('No users imported.'));
    }
    $form_state->setRedirectUrl(new Url('user_import.admin_upload'));
  }

}
