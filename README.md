# User Import (Drupal 8)
Import users into Drupal 8 from CSV file upload.

## Overview

This module currently assumes the following:

- Field: field_name_first (string)
- Field: field_name_last (string)
- CSV: In the following format:
    ```
    fname,lname,email
    ```

Usernames are generated from first+last+any digits to make name unique.
