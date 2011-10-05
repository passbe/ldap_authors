# LDAP Authors 

* Version: 0.3
* Author: [Ben Passmore](http://www.passbe.com)
* Build Date: 2011-10-05
* Requirements: Symphony 2.2.3

## Description
Allows LDAP users to login to the administration section of symphony. If an administrative users credentials fail, the LDAP Authors extension attempts to authenticate to the configured LDAP server. If successful the extension queries the authors table to find the authors details. If the author does not exist (ie: first time the LDAP user has logged in) the extension searches the LDAP server for the necessary details and adds the author.

## Usage
1. Add the `ldap_authors` folder to your Extensions directory
2. Enable the extension from the Extensions page
3. Configure LDAP settings found in your manifest/config.php

## Configuration
* `server` the LDAP server IP or Hostname. e.g. `ldap.example.com`
* `port` the LDAP server's port. Defaults to `389`
* `protocol_version` the LDAP protocol version. Defaults to version `3`
* `basedn` the Basedn path for your LDAP environment
* `filterdn` the filter path for LDAP user lookup. e.g. `cn=%username%`. Note: this string must contain `%username%` to search for the target user
* `first_name_key` the users first name LDAP key. e.g. `givenname`
* `last_name_key` the users last name LDAP key. e.g. `sn`
* `email_key` the users email LDAP key. e.g. `mail`
* `default_author_type` the default author type of new LDAP users

## Known issues
* changing an authors username and/or password is not possible, as this will de-associate the author from your LDAP environment
