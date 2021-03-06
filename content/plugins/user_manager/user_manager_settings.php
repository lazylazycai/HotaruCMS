<?php
/**
 * File: plugins/user_manager/user_manager_settings.php
 * Purpose: The functions that do the hard work such as adding, deleting and sorting categories.
 *
 * PHP version 5
 *
 * LICENSE: Hotaru CMS is free software: you can redistribute it and/or 
 * modify it under the terms of the GNU General Public License as 
 * published by the Free Software Foundation, either version 3 of 
 * the License, or (at your option) any later version. 
 *
 * Hotaru CMS is distributed in the hope that it will be useful, but WITHOUT 
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or 
 * FITNESS FOR A PARTICULAR PURPOSE. 
 *
 * You should have received a copy of the GNU General Public License along 
 * with Hotaru CMS. If not, see http://www.gnu.org/licenses/.
 * 
 * @category  Content Management System
 * @package   HotaruCMS
 * @author    Nick Ramsay <admin@hotarucms.org>
 * @copyright Copyright (c) 2009, Hotaru CMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link      http://www.hotarucms.org/
 */
    
class UserManagerSettings
{
    /**
     * Main function that calls others
     *
     * @return bool
     */
    public function settings($h)
    {
        if (($h->cage->get->testPage('subpage') == 'default_perms') 
            || ($h->cage->post->testPage('subpage') == 'default_perms')) {
            $this->defaultPerms($h);
            return true;
        }
        
        if (($h->cage->get->testPage('subpage') == 'default_settings')
            || ($h->cage->post->testPage('subpage') == 'default_settings')) {
            $this->defaultSettings($h);
            return true;
        }
        
        if (($h->cage->get->testPage('subpage') == 'add_user')
            || ($h->cage->post->testPage('subpage') == 'add_user')) {
            $this->addUserPage($h);
            return true;
        }
        
        // grab the number of pending users:
        $sql = "SELECT COUNT(user_id) FROM " . TABLE_USERS . " WHERE user_role = %s";
        $num_pending = $h->db->get_var($h->db->prepare($sql, 'pending'));
        if (!$num_pending) { $num_pending = "0"; } 
        $h->vars['num_pending'] = $num_pending; 
        
        
        // check if all new users are automatically set to pending or not
        $user_signin_settings = $h->getSerializedSettings('user_signin');
        $h->vars['regStatus'] = $user_signin_settings['registration_status'];
        $h->vars['useEmailConf'] = $user_signin_settings['emailconf_enabled'];
            
        // clear variables:
        $h->vars['search_term'] = '';
        if ($h->vars['regStatus'] == 'pending') { 
            $h->vars['user_filter'] = 'pending';
        } else {
            $h->vars['user_filter'] = 'all';
        }
        
        // Get unique statuses for Filter form:
        $h->vars['roles'] = $h->getUniqueRoles(); 
        
        $u = new UserBase();
        
        // if checkboxes
        if (($h->cage->get->getAlpha('type') == 'checkboxes') && ($h->cage->get->keyExists('user_man'))) 
        {
            foreach ($h->cage->get->keyExists('user_man') as $id => $checked) {
                $h->message = $h->lang["user_man_checkboxes_role_changed"]; // default "Changed role" message
                $u->id = $id;
                $u->getUser($h, $id);
                $new_role = $h->cage->get->testAlnumLines('checkbox_action');
                if ($new_role != $u->role) { 
                    // change role:
                    $u->role = $new_role;
                    $new_perms = $u->getDefaultPermissions($h, $new_role);
                    $u->setAllPermissions($new_perms);
                    $u->updatePermissions($h);
                    $u->updateUserBasic($h, $id);
                    $h->message = $h->lang["user_man_checkboxes_role_changed"];
                    
                    if ($new_role == 'killspammed' || $new_role == 'deleted') {
                        $h->deleteComments($u->id); // includes child comments from *other* users
                        $h->deletePosts($u->id); // includes tags and votes for self-submitted posts
                        if ($h->cage->get->keyExists('addblockedlist')) { 
                            $h->addToBlockedList($type = 'user', $value = $u->name, false);
                            $h->addToBlockedList($type = 'email', $value = $u->email, false);
                        }
                        $h->pluginHook('user_man_killspam_delete', '', array($u));
                        if ($new_role == 'deleted') { 
                            $u->deleteUser($h); 
                            $h->clearCache('db_cache', false); // clears them from User Manager list
                        }
                    }
                }
                
                $this->refreshUsersCache($h);
                
            }
        }
        
        
        // if search
        $search_term = '';
        if ($h->cage->get->getAlpha('type') == 'search') {
            $search_term = $h->cage->get->sanitizeTags('search_value');        
            if (strlen($search_term) < 3) {
                $h->message = $h->lang["user_man_search_too_short"];
                $h->messageType = 'red';
            } else {
                $h->vars['search_term'] = $search_term; // used to refill the search box after a search
                $where_clause = " WHERE user_username LIKE %s OR user_email LIKE %s"; 
                $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                $search_term = '%' . $search_term . '%';
                $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                $count = $h->db->get_var($h->db->prepare($count_sql, $search_term, $search_term));
                $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                $query = $h->db->prepare($sql, $search_term, $search_term); 
            }
        }
        
        
        // if filter
        $filter = '';
        if ($h->cage->get->getAlpha('type') == 'filter') {
            $filter = $h->cage->get->testAlnumLines('user_filter');
            $h->vars['user_filter'] = $filter;  // used to refill the filter box after use
            switch ($filter) {
                case 'all': 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql));
                    $sql = "SELECT * FROM " . TABLE_USERS . $sort_clause;
                    $query = $h->db->prepare($sql);
                    break;
                case 'not_killspammed': 
                    $where_clause = " WHERE user_role != %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'killspammed'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'killspammed');
                    break;
                case 'admin': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'admin'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'admin');
                    break;
                case 'supermod': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'supermod'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'supermod');
                    break;
                case 'moderator': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'moderator'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'moderator');
                    break;
                case 'member': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'member'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'member');
                    break;
                case 'pending': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'pending'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'pending');
                    break;
                case 'undermod': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'undermod'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'undermod');
                    break;
                case 'suspended': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'suspended'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'suspended');
                    break;
                case 'banned': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'banned'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'banned');
                    break;
                case 'killspammed': 
                    $where_clause = " WHERE user_role = %s"; 
                    $sort_clause = ' ORDER BY user_date DESC'; // ordered by registration date
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, 'killspammed'));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, 'killspammed');
                    break;
                case 'newest':
                    $sort_clause = ' ORDER BY user_date DESC';  // same as "all"
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS;
                    $count = $h->db->get_var($h->db->prepare($count_sql));
                    $sql = "SELECT * FROM " . TABLE_USERS . $sort_clause;
                    $query = $h->db->prepare($sql);
                    break;
                case 'oldest':
                    $sort_clause = ' ORDER BY user_date ASC';
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS;
                    $count = $h->db->get_var($h->db->prepare($count_sql));
                    $sql = "SELECT * FROM " . TABLE_USERS . $sort_clause;
                    $query = $h->db->prepare($sql);
                    break;
                case 'last_visited':
                    $sort_clause = ' ORDER BY user_lastvisit DESC';
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS;
                    $count = $h->db->get_var($h->db->prepare($count_sql));
                    $sql = "SELECT * FROM " . TABLE_USERS . $sort_clause;
                    $query = $h->db->prepare($sql);
                    break;
                default:
                    $where_clause = " WHERE user_role = %s"; $sort_clause = ' ORDER BY user_date DESC'; // ordered newest first for convenience
                    $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $count = $h->db->get_var($h->db->prepare($count_sql, $filter));
                    $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                    $query = $h->db->prepare($sql, $filter);    // filter = 'admin', 'member', etc.
                    break;
            }
        }

        if(!isset($query)) {
            // default list
            
            // if all new users are set to 'pending' show pending list as default...
            if ($h->vars['regStatus'] == 'pending') {
                $where_clause = " WHERE user_role = %s"; 
                $sort_clause = ' ORDER BY user_date DESC';
                $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $where_clause . $sort_clause;
                $count = $h->db->get_var($h->db->prepare($count_sql, 'pending'));
                $sql = "SELECT * FROM " . TABLE_USERS . $where_clause . $sort_clause;
                $query = $h->db->prepare($sql, 'pending'); 
            }
            // else show all users by newest...
            else
            {
                $sort_clause = ' ORDER BY user_date DESC'; // ordered by newest
                $count_sql = "SELECT count(*) AS number FROM " . TABLE_USERS . $sort_clause;
                $count = $h->db->get_var($h->db->prepare($count_sql));
                $sql = "SELECT * FROM " . TABLE_USERS . $sort_clause;
                $query = $h->db->prepare($sql); 
            }
        }

        $pagedResults = $h->pagination($query, $count, 30);
        
        if ($pagedResults) { 
            $h->vars['user_man_rows'] = $this->drawRows($h, $pagedResults, $filter, $search_term);
        } elseif ($h->vars['user_filter'] == 'pending') {
            $h->message = $h->lang['user_man_no_pending_users'];
            $h->messageType = 'green';
        }
        
        // Show template:
        $h->displayTemplate('user_man_main', 'user_manager');
    }
    
    
    /**
     * Draw Rows
     */
    public function drawRows($h, $pagedResults, $filter = '', $search_term = '')
    {
        $output = "";
        $alt = 0;
        
        if (!$pagedResults->items) { return ""; }
        
        foreach ($pagedResults->items as $user)
        {    //when $story is false loop terminates    
            $alt++;

            $account_link = BASEURL . "index.php?page=account&amp;user=" . $user->user_username; 
            $perms_link = BASEURL . "index.php?page=permissions&amp;user=" . $user->user_username; 
            if ($user->user_role == 'admin') { $disable = 'disabled'; } else { $disable = ''; }
            
            // add icons to user role:
            $user_icon = '';
            if ($h->vars['useEmailConf']) {
                if ($user->user_role == 'pending' && $user->user_email_valid == 0) {
                    $user_icon .= " <img src = '" . BASEURL . "content/plugins/user_manager/images/email.png' title='" . $h->lang["user_man_user_email_icon"] . "'>";
                }
            }
            // plugins can add other icons here
            $h->vars['user_manager_role'] = array($user_icon, $user->user_role, $user);
            $h->pluginHook('user_manager_role');
            $user_icon = $h->vars['user_manager_role'][0];
            
            $output .= "<tr class='table_row_" . $alt % 2 . "'>\n";
            $output .= "<td class='um_id'>" . $user->user_id . "</td>\n";
            $output .= "<td class='um_role'>" . $user->user_role . $user_icon . "</td>\n";
            $output .= "<td class='um_username'><a class='table_drop_down' href='#' title='" . $h->lang["user_man_show_content"] . "'>";
            $output .= $user->user_username . "</a></td>\n";
            $output .= "<td class='um_joined'>" . date('d M y', strtotime($user->user_date)) . "</a></td>\n";
            $output .= "<td class='um_account'>" . "<a href='" . $account_link . "'>" . $h->lang["user_man_account"] . "</a>\n";
            $output .= "<td class='um_perms'>" . "<a href='" . $perms_link . "'>" . $h->lang["user_man_perms"] . "</a>\n";
            $output .= "<td class='um_check'><input type='checkbox' name='user_man[" . $user->user_id . "]' value='" . $user->user_id . "' " . $disable . "></td>\n";
            $output .= "</tr>\n";

            $output .= "<tr class='table_tr_details' style='display:none;'>\n";
            $output .= "<td colspan=7 class='table_description um_description'>\n";
            $output .= "<a class='table_hide_details' style='float: right;' href='#'>[" . $h->lang["admin_theme_plugins_close"] . "]</a>";
            
            if ($user->user_role == 'pending') { 
                // show register date info:
                $output .= $user->user_username . " " . $h->lang["user_man_user_registered_on"] ." " . date('H:i:s \o\n l, F jS Y', strtotime($user->user_date));
                if ($h->vars['useEmailConf']) {
                    if ($user->user_email_valid == 0) {
                        $output .= $h->lang["user_man_user_email_not_validated"] . "\n";
                    } else {
                        $output .= $h->lang["user_man_user_email_validated"] . "\n";
                    }
                }                
            } else {
                // show last login amd submissions info:
                $output .= $user->user_username . " " . $h->lang["user_man_user_last_logged_in"] ." " . date('H:i:s \o\n l, F jS Y', strtotime($user->user_lastlogin)) . ".<br />\n";
                $output .= $h->lang["user_man_user_submissions_1"] . " " . $user->user_username . $h->lang["user_man_user_submissions_2"] . " <a href='" . $h->url(array('user'=>$user->user_username)) . "'>" . $h->lang['user_man_here'] . ".</a>\n";
            }
            
            // plugin hook (StopSpam plugin adds a note about why a user is pending)
            $h->vars['user_manager_details'] = array($output, $user);
            $h->pluginHook('user_manager_details');
            $output = $h->vars['user_manager_details'][0]; // $output
            $output .= "<br />";
    		$output .= "<i>" . $h->lang['user_man_ip_address'] . "</i> " . $user->user_ip . "<br />";
            $output .= "<i>" . $h->lang['user_man_email'] . "</i> <a href='mailto:" . $user->user_email . "'>$user->user_email</a>";
            $output .= "</td></tr>";
        }
        
        if ($pagedResults) {
            $h->vars['user_man_navi'] = $h->pageBar($pagedResults);
        }
        
        return $output;
    }
    
    
    /**
     * Edit Default Permissions
     */
    public function defaultPerms($h)
    {
        $role = $h->cage->get->testAlpha('role');
        if (!$role) { $role = $h->cage->post->testAlpha('role'); }
        if ($role) {
            $h->vars['user_man_role'] = $role;
        } else {
            $h->vars['user_man_role'] = 'member';
        }
        
        $h->vars['user_man_perms_existing'] = ""; // disable applying changes to other users by default
        
        // prevent non-admin user viewing permissions of admin user
        if (($h->vars['user_man_role'] == 'admin') && ($h->currentUser->role != 'admin')) {
            $h->showMessage($h->lang["user_man_admin_access_denied"], 'red');
            return true;
        }

        // if the form has been submitted...
        if (($h->cage->post->testAlnumLines('subpage') == 'default_perms') && (($h->cage->post->testAlpha('submitted') == 'true'))) {

            // No CSRF check here because all plugin setting pages are already checked.
            
            // get all existing site permissions:
            $sql = "SELECT miscdata_value FROM " . TABLE_MISCDATA . " WHERE miscdata_key = %s";
            $old_perms = $h->db->get_var($h->db->prepare($sql, 'permissions'));
            $new_perms = unserialize($old_perms);
            foreach ($new_perms as $perm => $roles) {
                if ($perm == 'options') { continue; }
                $updated = false;
                foreach ($roles as $role => $value) {
                    if ($role == $h->vars['user_man_role']) {
                        $new_perms[$perm][$role] = $h->cage->post->testAlnumLines($perm);
                        $updated = true;
                    }
                }
                // if no permission found for this role so make one:
                if (!$updated) {
                    $new_perms[$perm][$h->vars['user_man_role']] = $h->cage->post->testAlnumLines($perm);
                }
            }
            
            // save updated site permissions:
            $sql = "UPDATE " . TABLE_MISCDATA . " SET miscdata_value = %s, miscdata_updateby = %d WHERE miscdata_key = %s";
            $h->db->query($h->db->prepare($sql, serialize($new_perms), $h->currentUser->id, 'permissions'));
            
            $h->message = $h->lang["user_man_perms_updated"];
            $h->messageType = 'green';
        }

        // revert to original defaults for this usergroup
        if (($h->cage->get->testAlnumLines('subpage') == 'default_perms') && (($h->cage->get->testAlpha('revert') == 'true'))) {
        
            // get original base permissions:
            $sql = "SELECT miscdata_default FROM " . TABLE_MISCDATA . " WHERE miscdata_key = %s";
            $base_perms = $h->db->get_var($h->db->prepare($sql, 'permissions'));
            if (!$base_perms) { $base_perms = array(); } else { $base_perms = unserialize($base_perms); }
            //echo "BASE PERMS: " . "<br />";
            //echo "<pre>"; print_r($base_perms); echo "</pre>";
            
            // get site permissions:
            $sql = "SELECT miscdata_value FROM " . TABLE_MISCDATA . " WHERE miscdata_key = %s";
            $site_perms = $h->db->get_var($h->db->prepare($sql, 'permissions'));
            if (!$site_perms) { $site_perms = array(); } else { $site_perms = unserialize($site_perms); }
            //echo "SITE PERMS: " . "<br />";
            //echo "<pre>"; print_r($site_perms); echo "</pre>";
            
            // remove role from site perms
            foreach ($site_perms as $perm => $roles) {
                if ($perm == 'options') { unset($site_perms[$perm]); continue; }
                foreach ($roles as $role => $value) {
                    if ($role == $h->vars['user_man_role']) {
                        unset($site_perms[$perm][$role]);
                    }
                }
            }
            
            //merge arrays
            $site_perms = array_merge($site_perms, $base_perms);
            
            //echo "MERGED PERMS: " . "<br />";
            //echo "<pre>"; print_r($site_perms); echo "</pre>";
            
            // save updated site permissions:
            $sql = "UPDATE " . TABLE_MISCDATA . " SET miscdata_value = %s, miscdata_updateby = %d WHERE miscdata_key = %s";
            $h->db->query($h->db->prepare($sql, serialize($site_perms), $h->currentUser->id, 'permissions'));
            
            $h->message = $h->lang["user_man_perms_reverted"];
            $h->messageType = 'green';
        }
        
        // revert all usergroups to original defaults
        if (($h->cage->get->testAlnumLines('subpage') == 'default_perms') && (($h->cage->get->testAlpha('revert') == 'all'))) {
        
            // get original base permissions:
            $sql = "SELECT miscdata_default FROM " . TABLE_MISCDATA . " WHERE miscdata_key = %s";
            $base_perms = $h->db->get_var($h->db->prepare($sql, 'permissions'));
            
            // overwrite site permissions:
            if ($base_perms) {
                $sql = "UPDATE " . TABLE_MISCDATA . " SET miscdata_value = %s, miscdata_updateby = %d WHERE miscdata_key = %s";
                $h->db->query($h->db->prepare($sql, $base_perms, $h->currentUser->id, 'permissions'));
            }
            
            $h->message = $h->lang["user_man_all_perms_reverted"];
            $h->messageType = 'green';
        }
        
        // wipe all defaults and reinstall plugins
        if (($h->cage->get->testAlnumLines('subpage') == 'default_perms') && (($h->cage->get->testAlpha('revert') == 'complete'))) {
            
            // delete defaults:
            $sql = "DELETE FROM " . TABLE_MISCDATA . " WHERE miscdata_key = %s";
            $h->db->query($h->db->prepare($sql, 'permissions'));
            
            // Default permissions
            $perms['options']['can_access_admin'] = array('yes', 'no');
            $perms['can_access_admin']['admin'] = 'yes';
            $perms['can_access_admin']['supermod'] = 'yes';
            $perms['can_access_admin']['default'] = 'no';
            $perms = serialize($perms);
            
            $sql = "INSERT INTO " . TABLE_MISCDATA . " (miscdata_key, miscdata_value, miscdata_default, miscdata_updateby) VALUES (%s, %s, %s, %d)";
            $h->db->query($h->db->prepare($sql, 'permissions', $perms, $perms, $h->currentUser->id));
            
            $h->message = $h->lang["user_man_all_perms_deleted"];
            $h->messageType = 'green';
        }
        
        
        // get permissions from the database
        $h->vars['tempPermissionsCache'] = array(); // clear the cache
        $perm_options = $h->getDefaultPermissions('', 'site', true);
        $default_perms = $h->getDefaultPermissions($h->vars['user_man_role'], 'site');
        
        // update existing users?
        if ($h->cage->post->keyExists('apply_perms')) {
            $sql = "UPDATE " . TABLE_USERS . " SET user_permissions = %s, user_updateby = %d WHERE user_role = %s";
            $h->db->query($h->db->prepare($sql, serialize($default_perms), $h->currentUser->id, $h->vars['user_man_role']));
        }
        
        $h->vars['perm_options'] = '';
        foreach ($perm_options as $key => $options) {
            $h->vars['perm_options'] .= "<tr><td>" . make_name($key) . ": </td>\n";
            foreach($options as $value) {
                if (isset($default_perms[$key]) && ($default_perms[$key] == $value)) { $checked = 'checked'; } else { $checked = ''; } 
                if ($key == 'can_access_admin' && ($h->vars['user_man_role'] == 'admin')) { $disabled = 'disabled'; } else { $disabled = ''; }
                $h->vars['perm_options'] .= "<td><input type='radio' name='" . $key . "' value='" . $value . "' " . $checked . " " . $disabled . "> " . $value . " &nbsp;</td>\n";
            }
            $h->vars['perm_options'] .= "</tr>";
        }
        
        // Show template:
        $h->displayTemplate('user_man_perms', 'user_manager');
    }
    
    
    /**
     * Edit Default Settings
     */
    public function defaultSettings($h)
    {
        // prevent non-admin user viewing permissions of admin user
        if ($h->currentUser->role != 'admin') {
            $h->showMessage($h->lang["user_man_admin_access_denied"], 'red');
            return true;
        }
        
        $h->vars['user_man_user_settings_existing'] = ""; // disable forcing changes on other users by default

        // if the form has been submitted...
        if (($h->cage->post->testAlnumLines('subpage') == 'default_settings') && (($h->cage->post->testAlpha('submitted') == 'true'))) {

            // No CSRF check here because all plugin setting pages are already checked.
            
            // plugin hook
            $h->pluginHook('user_settings_pre_save');
            
            // save updated site permissions:
            $sql = "UPDATE " . TABLE_MISCDATA . " SET miscdata_value = %s, miscdata_updateby = %d WHERE miscdata_key = %s";
            $h->db->query($h->db->prepare($sql, serialize($h->vars['settings']), $h->currentUser->id, 'user_settings'));
            
            $default_settings = $h->vars['settings'];
            
            $h->message = $h->lang["user_man_user_settings_updated"];
            $h->messageType = 'green';
        }

        // revert all to original defaults
        if (($h->cage->get->testAlnumLines('subpage') == 'default_settings') && (($h->cage->get->testAlpha('revert') == 'all'))) {
        
            // get original base settings:
            $sql = "SELECT miscdata_default FROM " . TABLE_MISCDATA . " WHERE miscdata_key = %s";
            $base_settings = $h->db->get_var($h->db->prepare($sql, 'user_settings'));
            
            // overwrite site settings:
            if ($base_settings) {
                $sql = "UPDATE " . TABLE_MISCDATA . " SET miscdata_value = %s, miscdata_updateby = %d WHERE miscdata_key = %s";
                $h->db->query($h->db->prepare($sql, $base_settings, $h->currentUser->id, 'user_settings'));
            }
            
            $default_settings = unserialize($base_settings);
            
            $h->message = $h->lang["user_man_all_user_settings_reverted"];
            $h->messageType = 'green';
        }
        
        // wipe all defaults and reinstall plugins
        if (($h->cage->get->testAlnumLines('subpage') == 'default_settings') && (($h->cage->get->testAlpha('revert') == 'complete'))) {
            
            // delete defaults:
            $sql = "UPDATE " . TABLE_MISCDATA . " SET miscdata_value = %s, miscdata_default = %s, miscdata_updateby = %d WHERE miscdata_key = %s";
            $h->db->query($h->db->prepare($sql, '', '', $h->currentUser->id, 'user_settings'));
            
            $default_settings = array();
            
            $h->message = $h->lang["user_man_all_user_settings_deleted"];
            $h->messageType = 'green';
        }
        
        
        // get default settings from the database if we don't already have them:
        if (!isset($default_settings)) {
            $sql = "SELECT miscdata_value FROM " . TABLE_MISCDATA . " WHERE miscdata_key = %s";
            $default_settings = $h->db->get_var($h->db->prepare($sql, 'user_settings'));
            $default_settings = unserialize($default_settings);
        }
        
        // update existing users?
        if ($h->cage->post->keyExists('force_settings')) {
            $sql = "UPDATE " . TABLE_USERMETA . " SET usermeta_value = %s, usermeta_updateby = %d WHERE usermeta_key = %s";
            $h->db->query($h->db->prepare($sql, serialize($default_settings), $h->currentUser->id, 'user_settings'));
        }
        
        $h->vars['settings'] = $default_settings;

        // Show template:
        $h->displayTemplate('user_man_user_settings', 'user_manager');
    }
    
    
    /**
     * add User Page
     */
    public function addUserPage($h)
    {
        switch ($h->cage->post->testAlnumLines('submitted'))
        {
            case 'new_user':
                $this->createUser($h);
                break;
            case 'new_password':
                $this->sendPassword($h);
                break;
            case 'email_validation':
                $this->sendEmailValidationRequest($h);
                break;
        }
        
        // one username for each of the three forms, otherwise they all get pre-filled
        if (!isset($h->vars['user_man_username_1'])) { $h->vars['user_man_username_1'] = ''; }
        if (!isset($h->vars['user_man_username_2'])) { $h->vars['user_man_username_2'] = ''; }
        if (!isset($h->vars['user_man_username_3'])) { $h->vars['user_man_username_3'] = ''; }
        if (!isset($h->vars['user_man_email'])) { $h->vars['user_man_email'] = ''; }
        
        $h->displayTemplate('user_man_add');
    }
    
    
    /**
     * Create a new user
     */
    public function createUser($h)
    {
        $error = 0;

        // check username
        $username = $h->cage->post->testUsername('username'); // alphanumeric, dashes and underscores okay, case insensitive
        if (!$username) {
            $h->messages[$h->lang['user_signin_register_username_error']] = 'red';
            $error = 1;
        } else {
            $h->vars['user_man_username_1'] = $username;
        }
        
        // check email
        $email = $h->cage->post->testEmail('email');
        if (!$email) {
            $h->messages[$h->lang['user_signin_register_email_error']] = 'red';
            $error = 1;
        } else {
            $h->vars['user_man_email'] = $email;
        }
        
        // process new user
        if (!$error) {
            $us = new UserSignin();
            $blocked = $us->checkBlocked($h, $username, $email); // true if blocked, false if safe
            $exists = $h->userExists(0, $username, $email);
            if (!$blocked && ($exists == 'no')) {
                
                // SUCCESS!!!
                $userAuth = new UserAuth();
                $userAuth->name = $username;
                $userAuth->email = $email;
                $userAuth->emailValid = 1;
                $userAuth->password = random_string(10); // temporary until user is created
                $userAuth->addUserBasic($h);
                $last_insert_id = $h->db->get_var($h->db->prepare("SELECT LAST_INSERT_ID()"));
                
                // send password!
                $passconf = md5(crypt(md5($userAuth->email),md5($userAuth->email)));
                $userAuth->newRandomPassword($h, $last_insert_id, $passconf);
                $h->messages[$h->lang['user_man_add_success_password_sent']] = 'green';
                
                $user = ''; $email = ''; // clear the form.
                
            } elseif ($exists == 'id') {
                $h->messages[$h->lang['user_signin_register_id_exists']] = 'red';
    
            } elseif ($exists == 'name') {
                $h->messages[$h->lang['user_signin_register_username_exists']] = 'red';
    
            } elseif ($exists == 'email') {
                $h->messages[$h->lang['user_signin_register_email_exists']] = 'red';
                
            } elseif ($blocked) {
                $h->messages[$h->lang['user_signin_register_user_blocked']] = 'red';
            }
        }
    }
    
    
    /**
     * Send new password
     */
    public function sendPassword($h)
    {
        // check username
        $username = $h->cage->post->testUsername('username');
        
        $userAuth = new UserAuth();
        $userAuth->getUserBasic($h, 0, $username);
        if ($userAuth->id) {
            // send password!
            $passconf = md5(crypt(md5($userAuth->email),md5($userAuth->email)));
            $userAuth->newRandomPassword($h, $userAuth->id, $passconf);
            $h->messages[$h->lang['user_man_new_password_sent']] = 'green';
        } else {
            $h->vars['user_man_username_2'] = $username; // to fill the username field 
            $h->messages[$h->lang['user_man_user_not_found']] = 'red';
        }
    }
    
    
    /**
     * Send email validation request
     */
    public function sendEmailValidationRequest($h)
    {
        // check username
        $username = $h->cage->post->testUsername('username');
        $userid = $h->getUserIdFromName($username);
        
        if ($userid) {
            // send email validation request
            $us = new UserSignin();
            $us->sendConfirmationEmail($h, $userid);
            $h->messages[$h->lang['user_man_email_validation_request_sent']] = 'green';
        } else {
            $h->vars['user_man_username_3'] = $username; // to fill the username field 
            $h->messages[$h->lang['user_man_user_not_found']] = 'red';
        }
    }
    
    
	/**
	 * Refresh User Cache
	 * This little hack clears the cached update time so data is refreshed
	 */
	public function refreshUsersCache($h)
	{
		unset($h->vars['last_updates']['users']);
	}
}
?>
