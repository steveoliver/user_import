<?php
/**
 * @file
 * contains \Drupal\user_import\Controller\UserImportController.
 */

namespace Drupal\user_import\Controller;

use \DateTime;
use \DateInterval;
use \Drupal\user\Entity\User;
use \Drupal\file\Entity\File;
use \Drupal\Core\Entity\EntityStorageException;

class UserImportController {

  /**
   * Page callback.
   *
   * @return array
   *   The user import form.
   */
  public static function importPage() {
    $form = \Drupal::formBuilder()->getForm('Drupal\user_import\Form\UserImportForm');
    return $form;
  }

  /**
   * Processes an uploaded CSV file, creating a new user for each row of values.
   *
   * @param \Drupal\file\Entity\File $file
   *   The File entity to process.
   *
   * @param array $config
   *   An array of configuration containing:
   *   - roles: an array of role ids to assign to the user
   *
   * @return array
   *   An multi-dimensional associative array with 'created' and 'added' arrays
   *   of values from the filename keyed by new uid.
   */
  public static function processUpload(File $file, array $config) {
    $handle = fopen($file->destination, 'r');
    $today = new \DateTime();
    $added = [];
    $created = [];
    while ($row = fgetcsv($handle)) {
      if ($values = self::prepareUploadRow($row, $config)) {
        if (!empty($values['date'])) {
          // Future date? Add to waitlist.
          $date = new \DateTime($values['date']);
          $days_diff = (int) $today->diff($date)->format('%r%d');
          if ($days_diff > 0) {
            if ($id = self::addUserToWaitlist($values)) {
              $added[$id] = $values;
            }
          }
          // Today or previous date? Create user now with training date.
          else {
            if ($id = self::createUser($values)) {
              $created[$id] = $values;
            }
          }
        }
        else {
          if ($id = self::createUser($values)) {
            $created[$id] = $values;
          }
        }
      }
    }

    return [
      'added' => $added,
      'created' => $created
    ];
  }

  /**
   * Adds a user import entry to the waitlist table.
   *
   * @param array $user
   *
   * @return $this|bool
   */
  private function addUserToWaitlist($user) {
    $connection = \Drupal::database();
    if ($id = $connection->insert('user_import_waitlist')
    ->fields([
      'first',
      'last',
      'email',
      'date',
      'roles'
    ], [
      $user['first'],
      $user['last'],
      $user['email'],
      $user['date'],
      implode(',', $user['roles'])
    ])->execute()) {
      return $id;
    }
    return FALSE;
  }

  /**
   * Processes today's waitlist.
   *
   * @return array
   *   An associative array of users created from today's waitlist:
   *     - success: A sub-array of entries successfully created.
   *     - error: A sub-array of entries not successfully created.
   */
  public static function processTodaysWaitList() {
    $created = [
      'success' => [],
      'error' => []
    ];
    if ($waitlist = self::getTodaysWaitlist()) {
      foreach ($waitlist as $entry) {
        if ($uid = self::createUser($entry)) {
          $created['success'][] = $entry;
          self::removeUserFromWaitList($entry);
        }
        else {
          $created['error'][] = $entry;
        }
      }
    }

    return $created;
  }

  /**
   * Processes one-week-from-today's waitlist.
   *
   * @return array
   *   An associative array of users created from next week's waitlist:
   *     - success: A sub-array of entries successfully created.
   *     - error: A sub-array of entries not successfully created.
   */
  public static function processNextWeeksWaitList() {
    $created = [
      'success' => [],
      'error' => []
    ];
    if ($waitlist = self::getTodaysOneWeekWaitlist()) {
      foreach ($waitlist as $entry) {
        if ($uid = self::createUser($entry)) {
          $created['success'][] = $entry;
          self::removeUserFromWaitList($entry);
        }
        else {
          $created['error'][] = $entry;
        }
      }
    }

    return $created;
  }

  /**
   * Removes a user from the waitlist.
   *
   * @param array $entry
   *   An entry from ::getTodaysWaitList() whose 'email' property
   *   is used to find the waitlist entry to delete.
   *
   * @return bool|int
   *   ID of the record deleted, or FALSE in case of an error.
   */
  private static function removeUserFromWaitList($entry) {
    $connection = \Drupal::database();
    if ($id = $connection->delete('user_import_waitlist')
    ->condition('email', $entry['email'])
    ->execute()) {
      return $id;
    }
    return FALSE;
  }

  /**
   * Gets today's waitlist of users to activate.
   *
   * @return array
   *   All rows in the {user_import_waitlist} table with today's date.
   */
  private static function getTodaysWaitList() {
    $waitlist = [];
    $today = new DateTime();
    $connection = \Drupal::database();
    $query = $connection->select('user_import_waitlist', 'uiw')
      ->fields('uiw')
      ->condition('date', $today->format('Y-m-d'));
    $result = $query->execute();
    while ($entry = $result->fetchAssoc()) {
      $entry['roles'] = explode(',', $entry['roles']);
      $waitlist[] = $entry;
    }

    return $waitlist;
  }

  /**
   * Gets one-week-from-today's waitlist of users to activate.
   *
   * @return array
   *   All rows in the {user_import_waitlist} table with 1 week from today's date.
   */
  private static function getTodaysOneWeekWaitList() {
    $waitlist = [];
    $date = new DateTime();
    $date->add(DateInterval::createFromDateString('1 week'));
    $connection = \Drupal::database();
    $query = $connection->select('user_import_waitlist', 'uiw')
      ->fields('uiw')
      ->condition('date', $date->format('Y-m-d'));
    $result = $query->execute();
    while ($entry = $result->fetchAssoc()) {
      $entry['roles'] = explode(',', $entry['roles']);
      $waitlist[] = $entry;
    }

    return $waitlist;
  }

  /**
   * Prepares a new user import entry from an upload row and current config.
   *
   * @param $row
   *   A row from the currently uploaded file.
   *
   * @param $config
   *   An array of configuration containing:
   *   - roles: an array of role ids to assign to the user
   *
   * @return array
   *   User import values suitable for self::addUserToWaitList().
   */
  public static function prepareUploadRow(array $row, array $config) {
    return [
      'first' => $row[0],
      'last' => $row[1],
      'email' => $row[2],
      'date' => $row[3],
      'roles' => array_values($config['roles']),
    ];
  }

  /**
   * Returns user whose name matches $username.
   *
   * @param string $username
   *   Username to check.
   *
   * @return array
   *   Users whose names match username.
   */
  private static function usernameExists($username) {
    return \Drupal::entityQuery('user')->condition('name', $username)->execute();
  }

  /**
   * Prepares an array of new user values from a user waitlist entry.
   *
   * @param array $user
   *   An entry from the waitlist database table.
   *
   * @return array
   *   An array of user values suitable for User::save().
   */
  private static function prepareNewUser($user) {
    $preferred_username = strtolower($user['first'] . $user['last']);
    $i = 0;
    while (self::usernameExists($i ? $preferred_username . $i : $preferred_username)) {
      $i++;
    }
    $username = $i ? $preferred_username . $i : $preferred_username;
    $user['name'] = $username;
    $user['mail'] = $user['email'];
    $user['field_name_first'] = $user['first'];
    $user['field_name_last'] = $user['last'];
    $user['field_barre_member_training_date'] = $user['date'];
    $user['status'] = 1;

    return $user;
  }

  /**
   * Creates a new user from prepared values.
   *
   * @param $values
   *   Values prepared from prepareRow().
   *
   * @return \Drupal\user\Entity\User
   *
   */
  private static function createUser($values) {
    $values = self::prepareNewUser($values);
    $user = User::create($values);
    try {
      if ($user->save()) {
        return $user->id();
      }
    }
    catch (EntityStorageException $e) {
      drupal_set_message(t('Could not create user %fname %lname (username: %uname) (email: %email); exception: %e', [
        '%e' => $e->getMessage(),
        '%fname' => $values['field_name_first'],
        '%lname' => $values['field_name_last'],
        '%uname' => $values['name'],
        '%email' => $values['email'],
      ]), 'error');
    }
    return FALSE;
  }

}
