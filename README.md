# User Import (Drupal 8)
Import users into Drupal 8 from CSV file upload.

## Overview

This module currently assumes the following:

- Field: uid (int)
- Field: field_name_first (string)
- Field: field_name_last (string)
- Field: email (string)
- Field: username (string)
- Field: password (string)
- CSV: In the following format:
    ```
   uid,fname,lname,email,username,password
    ```
CSV may not contain someone of the next fields: uid, username and password.
-Usernames are generated from first+last+any digits to make name unique.
-Password null
-Uid automatic
