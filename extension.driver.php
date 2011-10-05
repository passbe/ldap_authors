<?php

class extension_ldap_authors extends Extension {
   
   public function about() {
      return array(
               'name' => 'LDAP Authors',
               'version' => '0.3',
               'release-date' => '2011-10-05',
               'author' => array(
                  'name' => 'Ben Passmore',
                  'website' => 'http://www.passbe.com'
               ),
               'description' => 'Provides the ability to login with LDAP credentials'
      );
   }

   public function getSubscribedDelegates() {
      return array(
         array(
            'page' => '/login/',
            'delegate' => 'AuthorLoginFailure',
            'callback' => 'ldap_login'
         ),
         array(
            'page' => '/backend/',
            'delegate' => 'InitaliseAdminPageHead',
            'callback' => 'author_edit_script'
         )
      );
   }

   //Alert Authors table
   public function install() {
      try {
         Symphony::Database()->query("ALTER TABLE `tbl_authors` ADD `LDAP` BOOLEAN NOT NULL DEFAULT '0'");
         Symphony::Configuration()->set('server', 'ldap.example.com', 'ldap_authors');
         Symphony::Configuration()->set('port', 389, 'ldap_authors');
         Symphony::Configuration()->set('protocol_version', 3, 'ldap_authors');
         Symphony::Configuration()->set('basedn', 'ou=Company,dc=domain', 'ldap_authors');
         Symphony::Configuration()->set('filterdn', 'cn=%username%', 'ldap_authors');
         Symphony::Configuration()->set('first_name_key', 'givenname', 'ldap_authors');
         Symphony::Configuration()->set('last_name_key', 'sn', 'ldap_authors');
         Symphony::Configuration()->set('email_key', 'mail', 'ldap_authors');
         Symphony::Configuration()->set('default_author_type', 'author', 'ldap_authors');
         Administration::instance()->saveConfig();
         return true;
      } catch (Exception $e) {
         return false;
      }
   }

   //Delete LDAP users + remove LDAP column
   public function uninstall() {
      try {
         Symphony::Database()->query("DELETE FROM `tbl_authors` WHERE `LDAP` = 1");
         Symphony::Database()->query("ALTER TABLE `tbl_authors` DROP `LDAP`");
         Symphony::Configuration()->remove('ldap_authors');
         Administration::instance()->saveConfig();
         return true;
      } catch (Exception $e) {
         return false;
      }
   }

   //Attempt LDAP login procedure
   public function ldap_login($context) {
         if (!empty($context->username) || !empty($_POST['password']))
         {  
            //LDAP connection
            $ldap = ldap_connect(Symphony::Configuration()->get('server', 'ldap_authors'), Symphony::Configuration()->get('port', 'ldap_authors'));
            if ($ldap) {
               ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, Symphony::Configuration()->get('protocol_version', 'ldap_authors'));
               $filterdn = preg_replace('/\%username\%/', $context['username'], Symphony::Configuration()->get('filterdn', 'ldap_authors'));
               $basedn = Symphony::Configuration()->get('basedn', 'ldap_authors');
               try {
                  //Attempt to authenticate to the LDAP server
                  $bind = ldap_bind($ldap,  $filterdn.','.$basedn, $_POST['password']);
                  $user = AuthorManager::fetchByUsername($context['username']);
                  if (count($user) > 0 && $user->get('LDAP') === '1') {
                     //LDAP user has visited before therefore login
                     $this->login($user);
                     return true;
                  } else {
                     //New LDAP user, we need to insert their details in the authors table
                     $ldap_user = $this->ldap_retrieve_user($ldap, $basedn, $filterdn);
                     if ($ldap_user) {
                        //Get attributes and insert data
                        $attrs = array(Symphony::Configuration()->get('first_name_key', 'ldap_authors'), Symphony::Configuration()->get('last_name_key', 'ldap_authors'), Symphony::Configuration()->get('email_key', 'ldap_authors'));
                        $author_details = $this->ldap_retrieve_attributes($attrs, $ldap_user[0]);
                        if (count($author_details) == 3) {
                          $id = AuthorManager::add(array(
                              'username' => $context['username'],
                              'password' => $this->fake_password(10),
                              'first_name' => $author_details[0],
                              'last_name' => $author_details[1],
                              'email' => $author_details[2],
                              'user_type' => Symphony::Configuration()->get('default_author_type', 'ldap_authors'),
                              'primary' => 'no',
                              'LDAP' => true
                          )); 
                          if ($id) {
                              //Once user is inserted log them in
                              $user = AuthorManager::fetchByID($id);
                              $this->login($user);
                              return true;
                          } else {
                              Symphony::$Log->pushToLog('[LDAP] Unable to insert LDAP user into Symphony authors table.', E_ERROR);
                          }
                        } else {
                           Symphony::$Log->pushToLog('[LDAP] Unable to retireve first name, last name and email address from the LDAP server.', E_ERROR);
                        }
                     } else { 
                        Symphony::$Log->pushToLog('[LDAP] Authentication with the LDAP server was successful, however unable to find LDAP user details.', E_ERROR);
                     }
                  }
               } catch (Exception $e) { 
                  Symphony::$Log->pushToLog('[LDAP] Unable to bind to LDAP server, this could be misconfiguration or invalid credentials. (User: "'.$context['username'].'")', E_WARNING);
               }
               return false;
            } else {
               Symphony::$Log->pushToLog('[LDAP] Unable to connect to LDAP server, please check configuration.', E_ERROR);
            }
         }
   }

   //Retrieves an array of attribute keys from LDAP data structure
   private function ldap_retrieve_attributes($attributes, $data) {
      $results = array();
      if (is_array($attributes) && is_array($data)) {
        foreach ($attributes as $attr) {
            if (array_key_exists($attr, $data)) {
               array_push($results, $data[$attr][0]);
            }
        }
      }
      return $results;
   }

   //Generates fake password
   private function fake_password($length = 10) {
      $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
      $string = '';
      for ($i=0; $i < $length; $i++) {
         $string .= $characters[mt_rand(0, strlen($characters))];
      }
      return General::hash($string);
   }

   //Retrieves LDAP user
   private function ldap_retrieve_user(&$ldap, $bind, $filter) {
      $search = ldap_search($ldap, $bind, $filter);
      if ($search) {
         $user = ldap_get_entries($ldap, $search);
         if ($user && array_key_exists('count', $user) && $user['count'] == 1) {
            return $user;
         }
      }
      return false;
   }

   //Tells Symphony to log the new LDAP user in
   private function login(&$user) {
      if (Administration::instance()->login($user->get('username'), $user->get('password'), true)) {
         if(isset($_POST['redirect'])) redirect(URL . str_replace(parse_url(URL, PHP_URL_PATH), '', $_POST['redirect']));
         redirect(SYMPHONY_URL);
      } else {
         Symphony::$Log->pushToLog('[LDAP] Unable to login user. Please check all configuration.', E_ERROR);
      }
   }

   //Insert script only on author_edit
   public function author_edit_script($context) {
      if (preg_match('/\/system\/authors\/edit\//', Administration::instance()->getCurrentPageURL())) {
         $id = (int)$context['parent']->Page->_context[1];
         if (Symphony::Database()->fetchVar('LDAP', 0, "SELECT `LDAP` FROM tbl_authors WHERE `id` = $id")) {
            $context['parent']->Page->addElementToHead(new XMLElement(
               'script',
               "jQuery(document).ready(function() {
                  jQuery('#change-password').remove();
                  jQuery('input[name=\"fields[username]\"]').parent().hide().parent().prepend('<label><br />This user has been imported by the \"LDAP Authors\" extension. It is not possible to update this authors username or password.</label>');
                });",
               array('type' => 'text/javascript')
            ), 1000);
         }
      }
   }
}

?>
