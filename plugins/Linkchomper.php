<?php
/*
Plugin Name: Linkchomper
Plugin URI: http://enanocms.org/Linkchomper
Description: Allows you to add custom links to the Links section of the sidebar. Includes click-tracking functionality.
Author: Dan Fuhry
Version: 0.1 beta 1
Author URI: http://enanocms.org/
*/

/*
 * Linkchomper for Enano CMS
 * Version 0.1 beta 1
 * Copyright (C) 2006-2007 Dan Fuhry
 *
 * This program is Free Software; you can redistribute and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for details.
 */
 
global $db, $session, $paths, $template, $plugins; // Common objects

// Constants

define('LC_LINK_RAW', 1);
define('LC_LINK_TRACK_CLICKS', 2);
define('LC_LINK_DISABLED', 4);
define('LC_LINK_INNER_IMAGE', 8);

define('LC_EDIT', 1);
define('LC_CREATE', 2);

if ( !$version = getConfig('linkchomper_version') )
{
  // Install the table
  $q = $db->sql_query('CREATE TABLE '.table_prefix.'linkchomper(
      link_id mediumint(8) NOT NULL auto_increment,
      link_name varchar(255) NOT NULL,
      link_href text NOT NULL,
      link_inner_html text NOT NULL,
      link_before_html text NOT NULL,
      link_after_html text NOT NULL,
      link_flags tinyint(1) NOT NULL DEFAULT 0,
      link_clicks bigint(15) NOT NULL DEFAULT 0,
      link_order mediumint(8) NOT NULL DEFAULT 0,
      PRIMARY KEY ( link_id )
    );');
  if ( !$q )
  {
    // Prevent Linkchomper from loading again
    $plugin_key = 'plugin_' . basename(__FILE__);
    setConfig($plugin_key, '0');
    $db->_die('The error occurred during an attempt to create the table for Linkchomper. For your site\'s protection, Linkchomper has disabled itself. It can be re-enabled in the administration panel.');
  }
  setConfig('linkchomper_version', '0.1b1');
}

// Hook into the template compiler

$plugins->attachHook('links_widget', 'linkchomper_generate_html($ob);');

// Add our link tracking page

$plugins->attachHook('base_classes_initted', '
  global $paths;
    $paths->add_page(Array(
      \'name\'=>\'LinkChomper click tracker\',
      \'urlname\'=>\'LCClick\',
      \'namespace\'=>\'Special\',
      \'special\'=>0,\'visible\'=>0,\'comments_on\'=>0,\'protected\'=>1,\'delvotes\'=>0,\'delvote_ips\'=>\'\',
      ));
    $paths->add_page(Array(
      \'name\'=>\'Administration\',
      \'urlname\'=>\'Linkchomper\',
      \'namespace\'=>\'Admin\',
      \'special\'=>0,\'visible\'=>0,\'comments_on\'=>0,\'protected\'=>1,\'delvotes\'=>0,\'delvote_ips\'=>\'\',
      ));
    $paths->addAdminNode(\'Plugin configuration\', \'Linkchomper manager\', \'Linkchomper\');
  ');


// Function to generate HTML for the sidebar widget

function linkchomper_generate_html(&$links_array)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  $q = $db->sql_query('SELECT link_id, link_name, link_href, link_inner_html, link_before_html, link_after_html, link_flags FROM '.table_prefix.'linkchomper ORDER BY link_order ASC;');
  if ( !$q )
    $db->_die();
  
  if ( $row = $db->fetchrow() )
  {
    do {
      
      // Get flags
      $flags =& $row['link_flags'];
      
      // Is this link disabled? If so, skip the whole painful process
      if ( $flags & LC_LINK_DISABLED )
        continue;
      
      // First check to see if the inner_html is an image URL. If so, generate a nice img tag for the inner HTML.
      if ( $flags & LC_LINK_INNER_IMAGE )
      {
        $row['link_inner_html'] = '<img alt="' . htmlspecialchars($row['link_name']) . '" src="' . htmlentities($row['link_inner_html']) . '" style="border-width: 0px;" />';
      }
      
      // If it's raw HTML, just send it through
      if ( $flags & LC_LINK_RAW )
      {
        $links_array[] = $row['link_before_html'] . $row['link_inner_html'] . $row['link_after_html'];
      }
      // If we're supposed to track clicks, send a deceptive anchor
      else if ( $flags & LC_LINK_TRACK_CLICKS )
      {
        $url = makeUrlNS('Special', 'LCClick/' . $row['link_id'], false, true);
        // Escape target URL for Javascript-safety
        $real_url = htmlspecialchars(str_replace(array('\\', '\'', '"'), array('\\\\', '\\\'', '\\"'), $row['link_href']));
        $link = $row['link_before_html'] . '<a href="' . $url . '" title="' . htmlspecialchars($row['link_href']) . '" onmouseover="void(window.status=\'' . $real_url . '\');" onmouseout="void(window.status=\'\');">' . $row['link_inner_html'] . '</a>' . $row['link_after_html'];
        $links_array[] = $link;
      }
      // None of those? OK just send a normal link
      else
      {
        $url = htmlspecialchars($row['link_href']);
        $link = $row['link_before_html'] . '<a href="' . $url . '">' . $row['link_inner_html'] . '</a>' . $row['link_after_html'];
        $links_array[] = $link;
      }
      
    } while ( $row = $db->fetchrow() );
  }
  
  $db->free_result();
  
}

// Special page handler for click tracker

function page_Special_LCClick()
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  $link_id = ( $xx = $paths->getParam(0) ) ? intval($xx) : false;
  
  if ( !$link_id )
    die_friendly('Nice try', '<p>Hacking attempt</p>');
  
  $q = $db->sql_query('SELECT link_href,link_flags FROM '.table_prefix.'linkchomper WHERE link_id=' . $link_id . ';');
  
  if ( !$q )
    $db->_die();
  
  $row = $db->fetchrow();
  $db->free_result();
  
  if ( ! ( $row['link_flags'] & LC_LINK_TRACK_CLICKS ) )
  {
    die_friendly('Nice try', '<p>This ain\'t no tracker link...</p>');
  }
  
  $q = $db->sql_query('UPDATE '.table_prefix.'linkchomper SET link_clicks=link_clicks+1 WHERE link_id=' . $link_id . ';');
  
  if ( !$q )
    $db->_die();
  
  redirect($row['link_href'], 'Redirecting', 'Thanks for clicking the link, you are now being transferred to the destination.', 0);
  
}

function linkchomper_admin_redirect_home($message = 'Your changes have been saved, and you will now be transferred back to the administration panel.')
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  $url = makeUrlComplete('Special', 'Administration', 'module=' . $paths->nslist['Admin'] . 'Linkchomper');
  redirect($url, 'Linkchomper changes saved', $message, 2);
}

function page_Admin_Linkchomper()
{
  
  //@ini_set('display_errors', 'On') or die('Can\'t set display_errors');
  //error_reporting(E_ALL);
  
  global $db, $session, $paths, $template, $plugins; // Common objects
  if ( $session->auth_level < USER_LEVEL_ADMIN || $session->user_level < USER_LEVEL_ADMIN )
  {
    echo '<h3>Error: Not authenticated</h3><p>It looks like your administration session is invalid or you are not authorized to access this administration page. Please <a href="' . makeUrlNS('Special', 'Login/' . $paths->nslist['Special'] . 'Administration', 'level=' . USER_LEVEL_ADMIN, true) . '">re-authenticate</a> to continue.</p>';
    return;
  }
  
  if ( isset($_POST['action']) )
  {
    if ( isset($_POST['action']['move_up']) || isset($_POST['action']['move_down']) )
    {
      $direction = ( isset($_POST['action']['move_up']) ) ? 'up' : 'down';
      $ordering  = ( isset($_POST['action']['move_up']) ) ? 'ASC' : 'DESC';
      
      // Move an item up in the list
      // First step: Get IDs of the item to move and the item above it
      $id = array_keys($_POST['action']['move_' . $direction]);
      $id = intval( $id[0] );
      if ( !$id )
      {
        echo 'Hacking attempt';
        return false;
      }
      
      $q = $db->sql_query('SELECT link_id, link_name, link_order FROM '.table_prefix.'linkchomper ORDER BY link_order ' . $ordering . ';');
      
      if ( !$q )
        $db->_die();
      
      $last_id = false;
      $this_id = false;
      
      $last_order = false;
      $this_order = false;
      
      $id_to   = false;
      $id_from = false;
      
      $order_to   = false;
      $order_from = false;
      
      while ( $row = $db->fetchrow() )
      {
        $this_id = $row['link_id'];
        $this_order = $row['link_order'];
        if ( $this_id == $id && $last_id === false )
        {
          linkchomper_admin_redirect_home('This item is already at the top or bottom of the list.');
        }
        else if ( $this_id == $id )
        {
          $id_from = $last_id;
          $id_to   = $this_id;
          $order_from = $this_order;
          $order_to   = $last_order;
          break;
        }
        $last_id = $this_id;
        $last_order = $this_order;
      }
      
      unset($this_id, $this_order, $last_id, $last_order);
      
      if ( $last_order === false || $this_order === false )
      {
        linkchomper_admin_redirect_home('Sanity check failed.');
      }
      
      $sql1 = 'UPDATE '.table_prefix.'linkchomper SET link_order=' . $order_to . ' WHERE link_id=' . $id_to . ';';
      $sql2 = 'UPDATE '.table_prefix.'linkchomper SET link_order=' . $order_from . ' WHERE link_id=' . $id_from . ';';
      
      if ( !$db->sql_query($sql1) )
      {
        $db->_die();
      }
      
      if ( !$db->sql_query($sql2) )
      {
        $db->_die();
      }
      
      linkchomper_admin_redirect_home('The item "' . $row['link_name'] . '" has been moved ' . $direction . '.');
      
    }
    else if ( isset($_POST['action']['delete']) )
    {
      // Delete a link
      $id = array_keys($_POST['action']['delete']);
      $id = intval( $id[0] );
      if ( !$id )
      {
        echo 'Hacking attempt';
        return false;
      }
      $q = $db->sql_query('DELETE FROM '.table_prefix."linkchomper WHERE link_id=$id;");
      if ( !$q )
        $db->_die();
      
      linkchomper_admin_redirect_home('The selected link has been deleted.');
    }
    
    linkchomper_admin_redirect_home('Invalid or no action defined');
  }
  
  else if ( isset($_POST['stage2']) )
  {
    $_GET['module'] = $paths->page;
    $_POST['stage2_real'] = $_POST['stage2'];
    unset($_POST['stage2']);
    page_Special_Administration();
    return true;
  }
  
  else if ( isset($_POST['stage2_real']) )
  {
  /*
  
  TODO:
  The idea here is to build a template-based unified edit form that will be used both for creating and editing links. Make it have
  intelligent auto-hiding/auto-(un)checking elements and make it use the standard flags field.
  
  */
    // allow breaking out
    switch(true){case true:
      $stage2 =& $_POST['stage2_real'];
      $err_and_revert = array(
        'error' => false
      );
      if ( isset($stage2['delete']) )
      {
        $id = array_keys($stage2['delete']);
        $id = intval( $id[0] );
        if ( !$id )
        {
          echo 'Hacking attempt';
          return false;
        }
        echo '<form action="' . makeUrlNS('Admin', 'Linkchomper') . '" method="post" enctype="multipart/form-data">';
        echo '<div class="tblholder">
                <table border="0" cellspacing="1" cellpadding="4">';
        echo '  <tr>
                  <th>Confirm deletion</th>
                </tr>
                <td class="row1" style="text-align: center; line-height: 40px;">
                  Are you sure you want to permanently delete this link?
                </td>
                <tr>
                  <th class="subhead">
                    <input type="submit" name="action[delete]['.$id.']" value="Yes, delete link" />
                    <input type="submit" name="stage2[cancel]" value="Cancel" />
                  </th>
                </tr>';
        
        echo '  </table>
              </div>';
        echo '</form>';
      }
      else if ( isset($stage2['create_new']) )
      {
        $editor = new LinkchomperFormGenerator();
        $editor->echo_html();
      }
      else if ( isset($stage2['edit']) )
      {
        $id = array_keys($stage2['edit']);
        $id = intval( $id[0] );
        if ( !$id )
        {
          echo 'Hacking attempt';
          return false;
        }
        $q = $db->sql_query('SELECT * FROM '.table_prefix."linkchomper WHERE link_id=$id;");
        if ( !$q )
          $db->_die();
        if ( $db->numrows() < 1 )
        {
          echo "Can't find link: $id";
          $db->free_result();
        }
        else
        {
          $row = $db->fetchrow();
          $db->free_result();
          $editor = new LinkchomperFormGenerator();
          $editor->track_clicks = ( $row['link_flags'] & LC_LINK_TRACK_CLICKS );
          $editor->raw_html = ( $row['link_flags'] & LC_LINK_RAW );
          $editor->link_flag_image = ( $row['link_flags'] & LC_LINK_INNER_IMAGE );
          $editor->link_disabled = ( $row['link_flags'] & LC_LINK_DISABLED );
          $editor->link_target = $row['link_href'];
          $editor->link_name = $row['link_name'];
          $editor->mode = LC_EDIT;
          $editor->inner_html = $row['link_inner_html'];
          $editor->before_html = $row['link_before_html'];
          $editor->after_html = $row['link_after_html'];
          $editor->link_id = $row['link_id'];
          $editor->echo_html();
        }
      }
      else if ( isset($stage2['create_new_finish']) )
      {
        $flags = 0;
        
        // Validation
        $errors = array();
        
        $link_name = trim($_POST['link_name']);
        if ( empty($link_name) )
          $errors[] = 'Please enter a name for your link.';
        
        if ( isset($_POST['raw_html']) && isset($_POST['track_clicks']) )
          $errors[] = 'Raw blocks cannot be used with clicktracking.';
        
        $link_target = trim($_POST['link_target']);
        if ( empty($link_target) && !isset($_POST['raw_html']) )
          $errors[] = 'Please enter a target for your link.';
        
        if ( $_POST['link_flag_img'] == '1' )
        {
          $inner_html = trim($_POST['link_img_path']);
          if ( empty($inner_html) )
            $errors[] = 'Please enter a path or URL to an image file.';
        }
        else
        {
          $inner_html = trim($_POST['link_inner_html']);
          if ( empty($inner_html) )
            $errors[] = 'Please enter some content to go inside your link.';
        }
        
        if ( count($errors) > 0 )
        {
          $err_and_revert['error'] = true;
          $err_and_revert['message'] = implode("<br />\n        ", $errors);
        }
        else
        {
          if ( isset($_POST['link_disabled']) )
            $flags = $flags | LC_LINK_DISABLED;
          if ( $_POST['link_flag_img'] == '1' )
            $flags = $flags | LC_LINK_INNER_IMAGE;
          if ( isset($_POST['raw_html']) )
            $flags = $flags | LC_LINK_RAW;
          if ( isset($_POST['track_clicks']) )
            $flags = $flags | LC_LINK_TRACK_CLICKS;
          
          $before_html = strval(trim($_POST['link_before_html']));
          $after_html  = strval(trim($_POST['link_after_html']));
          
          if ( !$session->get_permissions('php_in_pages') )
          {
            // Not allowed to embed PHP and Javascript
            $before_html = sanitize_html($before_html);
            $after_html  = sanitize_html($after_html);
            $inner_html  = sanitize_html($inner_html);
          }
          
          $sanitized = array(
              'link_name' => $db->escape($link_name),
              'link_target' => $db->escape($link_target),
              'link_inner_html' => $db->escape($inner_html),
              'link_before_html' => $db->escape($before_html),
              'link_after_html' => $db->escape($after_html)
            );
          
          $sql = "INSERT INTO ".table_prefix."linkchomper(link_name, link_href, link_inner_html, link_before_html, link_after_html, link_flags, link_order) VALUES('{$sanitized['link_name']}','{$sanitized['link_target']}','{$sanitized['link_inner_html']}','{$sanitized['link_before_html']}','{$sanitized['link_after_html']}', $flags, ".LC_ADMIN_ORDER_LAST.");";
          if ( !$db->sql_query($sql) )
            $db->_die();
          
          echo '<div class="info-box">Link created.</div>';
          break;
        }
      }
      else if ( isset($stage2['edit_finish']) )
      {
        $flags = 0;
        
        // Validation
        $errors = array();
        
        $link_name = trim($_POST['link_name']);
        if ( empty($link_name) )
          $errors[] = 'Please enter a name for your link.';
        
        if ( isset($_POST['raw_html']) && isset($_POST['track_clicks']) )
          $errors[] = 'Raw blocks cannot be used with clicktracking.';
        
        $link_target = trim($_POST['link_target']);
        if ( empty($link_target) && !isset($_POST['raw_html']) )
          $errors[] = 'Please enter a target for your link.';
        
        if ( $_POST['link_flag_img'] == '1' )
        {
          $inner_html = trim($_POST['link_img_path']);
          if ( empty($inner_html) )
            $errors[] = 'Please enter a path or URL to an image file.';
        }
        else
        {
          $inner_html = trim($_POST['link_inner_html']);
          if ( empty($inner_html) )
            $errors[] = 'Please enter some content to go inside your link.';
        }
        
        $link_id = intval($_POST['link_id']);
        if ( $link_id < 1 )
          $errors[] = 'Unable to obtain link ID';
        
        if ( count($errors) > 0 )
        {
          $err_and_revert['error'] = true;
          $err_and_revert['message'] = implode("<br />\n        ", $errors);
        }
        else
        {
          if ( isset($_POST['link_disabled']) )
            $flags = $flags | LC_LINK_DISABLED;
          if ( $_POST['link_flag_img'] == '1' )
            $flags = $flags | LC_LINK_INNER_IMAGE;
          if ( isset($_POST['raw_html']) )
            $flags = $flags | LC_LINK_RAW;
          if ( isset($_POST['track_clicks']) )
            $flags = $flags | LC_LINK_TRACK_CLICKS;
          
          $before_html = strval(trim($_POST['link_before_html']));
          $after_html  = strval(trim($_POST['link_after_html']));
          
          if ( !$session->get_permissions('php_in_pages') )
          {
            // Not allowed to embed PHP and Javascript
            $before_html = sanitize_html($before_html);
            $after_html  = sanitize_html($after_html);
            $inner_html  = sanitize_html($inner_html);
          }
          
          $sanitized = array(
              'link_name' => $db->escape($link_name),
              'link_target' => $db->escape($link_target),
              'link_inner_html' => $db->escape($inner_html),
              'link_before_html' => $db->escape($before_html),
              'link_after_html' => $db->escape($after_html)
            );
          
          $sql = "UPDATE ".table_prefix."linkchomper SET link_name='{$sanitized['link_name']}',link_href='{$sanitized['link_target']}',link_inner_html='{$sanitized['link_inner_html']}',link_before_html='{$sanitized['link_before_html']}',link_after_html='{$sanitized['link_after_html']}',link_flags=$flags WHERE link_id=$link_id;";
          if ( !$db->sql_query($sql) )
            $db->_die();
          
          echo '<div class="info-box">Your changes have been saved.</div>';
          break;
        }
      }
      else if ( isset($stage2['cancel']) )
      {
        break;
      }
      else
      {
        echo 'Undefined Superform handler:<pre>' . htmlspecialchars(print_r($stage2, true)) . '</pre>';
      }
      if ( $err_and_revert['error'] )
      {
        $editor = new LinkchomperFormGenerator();
        $editor->error = $err_and_revert['message'];
        $editor->track_clicks = ( isset($_POST['track_clicks']) && !isset($_POST['raw_html']) );
        $editor->raw_html = ( isset($_POST['raw_html']) && !isset($_POST['track_clicks']) );
        $editor->link_flag_image = ( $_POST['link_flag_img'] == '1' );
        $editor->link_disabled = ( isset($_POST['link_disabeld']) );
        $editor->link_target = $_POST['link_target'];
        $editor->link_name = $_POST['link_name'];
        $editor->mode = ( isset($stage2['create_new_finish']) ) ? LC_CREATE : LC_EDIT;
        $editor->inner_html = $_POST['link_inner_html'];
        $editor->before_html = $_POST['link_before_html'];
        $editor->after_html = $_POST['link_after_html'];
        $editor->link_id = ( isset($stage2['create_new_finish']) ) ? -1 : intval($_POST['link_id']);
        $editor->echo_html();
      }
      return true;
    }
  }
  
  echo <<<EOF
    <h3>Linkchomper link manager</h3>
    <p>Linkchomper is a plugin that allows you to add custom content to the "Links" block on your sidebar. You can add tracking links, raw HTML, or just normal links.</p>
EOF;
  
  echo '<form name="main" action="'.makeUrlNS('Admin', 'Linkchomper').'" method="post">';
  echo '<div class="tblholder">
        <table border="0" cellspacing="1" cellpadding="4">
          <tr>
            <th>Link name</th>
            <th>Link target</th>
            <th>Clicks</th>
            <th colspan="4" style="width: 50px;">Admin</th>
          </tr>';
  
  $q = $db->sql_query('SELECT link_id, link_name, link_href, link_flags, link_clicks, link_order FROM '.table_prefix.'linkchomper ORDER BY link_order ASC;');
  
  if ( !$q )
    $db->_die();
  
  $num_rows = $db->numrows();
  $i = 0;
  
  if ( $row = $db->fetchrow() )
  {
    do {
      echo '<tr>';
      echo '<td class="row1">' . htmlspecialchars($row['link_name']) . '</td>';
      echo '<td class="row2">' . ( ( $row['link_flags'] & LC_LINK_RAW ) ? '&lt;Raw HTML block&gt;' : '<a href="' . htmlspecialchars($row['link_href']) . '" onclick="window.open(this.href); return false;">' . htmlspecialchars($row['link_href']) . '</a>' ) . '</td>';
      echo '<td class="row1" style="text-align: center;">' . ( ( $row['link_flags'] & LC_LINK_TRACK_CLICKS ) ? $row['link_clicks'] : '' ) . '</td>';
      // Admin actions
      echo '<td class="row2" style="text-align: center;"><button ' . ( ( $i == 0 ) ? 'disabled="disabled"' : '' ) . ' name="action[move_up][' . $row['link_id'] . ']">&uarr;</button></td>';
      echo '<td class="row1" style="text-align: center;"><button ' . ( ( $i + 1 == $num_rows ) ? 'disabled="disabled"' : '' ) . ' name="action[move_down][' . $row['link_id'] . ']">&darr;</button></td>';
      echo '<td class="row2" style="text-align: center;"><button name="stage2[edit][' . $row['link_id'] . ']">Edit</button></td>';
      echo '<td class="row1" style="text-align: center;"><button name="stage2[delete][' . $row['link_id'] . ']">Delete</button></td>';
      echo '</tr>';
      $i++;
    } while ( $row = $db->fetchrow() );
  }
  else
  {
    echo '<tr>
            <td class="row1" colspan="7">
              You haven\'t created any links yet.
            </td>
          </tr>';
  }
  
  echo '<tr style="text-align: center;">
          <th class="subhead" colspan="7">
            <button name="stage2[create_new]"><b>Create new link</b></button>
          </th>
        </tr>';
  
  echo '</table></div>';
  echo '</form>';
  
  // */
  
}

// Hopefully no one will ever get 4 billion links in their sidebar.
define('LC_ADMIN_ORDER_LAST', ( pow(2, 33)-3 ));

/**
 * Class to generate edit forms for Linkchomper links.
 * @package Enano
 * @subpackage Linkchomper
 * @license GNU General Public License <http://www.gnu.org/licenses/gpl.html>
 */
 
class LinkchomperFormGenerator
{
  
  /**
   * What this editor instance does, create or edit. Should be LC_EDIT or LC_CREATE.
   * @var int
   */
  
  var $mode = LC_CREATE;
  
  /**
   * The name of the link.
   * @var string
   */
  
  var $link_name = '';
  
  /**
   * Link ID - only used when editing
   * @var int
   */
  
  var $link_id = -1;
  
  /**
   * Flag for raw HTML switch
   * @var bool
   */
  
  var $raw_html = false;
  
  /**
   * Flag for inner HTML field is an image URL
   * @var bool
   */
  
  var $image_url = false;
  
  /**
   * Flag to determine if clicks will be tracked
   * @var bool
   */
  
  var $track_clicks = false;
  
  /**
   * "Appear after" (link order)
   * @var int The link ID to appear after
   */
  
  var $appear_after = LC_ADMIN_ORDER_LAST;
  
  /**
   * Link target.
   * @var string
   */
  
  var $link_target = 'http://www.example.com/';
  
  /**
   * If the image flag is on, this should be set to true. Should only be used while editing.
   * @var bool
   */
  
  var $link_flag_image = false;
  
  /**
   * Set to true if the link is disabled (hidden)
   * @var bool
   */
  
  var $link_disabled = false;
  
  /**
   * The inner HTML (or image URL)
   * @var string
   */
  
  var $inner_html = '';
  
  /**
   * HTML shown before the link
   * @var string
   */
  
  var $before_html = '';
  
  /**
   * HTML shown after the link
   * @var string
   */
  
  var $after_html = '';
  
  /**
   * Unique identifier used for Javascript bits
   * @var string
   * @access private
   */
  
  var $uuid = '';
  
  /**
   * Error message to show at the top of the form. Default is false for no error.
   * @var string
   */
  
  var $error = '';
  
  /**
   * Constructor.
   */
  
  function __construct()
  {
    $uuid = md5( mt_rand() . microtime() . @file_get_contents('/proc/uptime') /* That last one's just for fun ;-) */ );
    if ( file_exists('/dev/urandom') )
    {
      $f = @fopen('/dev/urandom', 'r');
      if ( $f )
      {
        $random = fread($f, 16);
        $random = hexencode($random, '', '');
        fclose($f);
        $uuid = $random;
      }
    }
    $this->uuid = $uuid;
  }
  
  /**
   * PHP 4 constructor
   */
  
  function LinkchomperFormGenerator()
  {
    $this->__construct();
  }
  
  /**
   * Generates the ready to use HTML.
   * @return string
   */
   
  function get_html()
  {
    global $db, $session, $paths, $template, $plugins; // Common objects
    
    if ( !empty($template->theme) && file_exists(ENANO_ROOT . '/themes/' . $template->theme . '/linkchomper_editor.tpl') )
    {
      $parser = $template->makeParser('linkchomper_editor.tpl');
    }
    else
    {
      $tpl_code = <<<EOF
      <!-- start of Linkchomper editor -->
      <script type="text/javascript">
        // <![CDATA[
        
        // Helper javascript code (uuid={UUID})
        
        var check_box_raw_{UUID} = function()
        {
          var source = document.getElementById('raw_{UUID}');
          if ( source.checked )
          {
            var target = document.getElementById('trk_{UUID}');
            target.checked = false;
            target.disabled = true;
            target = document.getElementById('lc_tr_url_{UUID}');
            target.style.display = 'none';
            target = document.getElementById('lc_tr_beforehtml_{UUID}');
            target.style.display = 'none';
            target = document.getElementById('lc_tr_afterhtml_{UUID}');
            target.style.display = 'none';
          }
          else
          {
            var target = document.getElementById('trk_{UUID}');
            target.disabled = false;
            target = document.getElementById('lc_tr_url_{UUID}');
            target.style.display = null;
            target = document.getElementById('lc_tr_beforehtml_{UUID}');
            target.style.display = null;
            target = document.getElementById('lc_tr_afterhtml_{UUID}');
            target.style.display = null;
          }
        }
        
        var check_box_trk_{UUID} = function()
        {
          var source = document.getElementById('trk_{UUID}');
          if ( source.checked )
          {
            var target = document.getElementById('raw_{UUID}');
            target.checked = false;
            target.disabled = true;
          }
          else
          {
            var target = document.getElementById('raw_{UUID}');
            target.disabled = false;
          }
        }
        
        var radio_set_image_{UUID} = function()
        {
          var source = document.getElementById('is_img_{UUID}');
          if ( source.checked )
          {
            var target;
            target = document.getElementById('inner_html_{UUID}');
            target.style.display = 'none';
            target = document.getElementById('inner_img_{UUID}');
            target.style.display = 'block';
          }
          else
          {
            var target;
            target = document.getElementById('inner_html_{UUID}');
            target.style.display = 'block';
            target = document.getElementById('inner_img_{UUID}');
            target.style.display = 'none';
          }
        }
        
        addOnloadHook(check_box_raw_{UUID});
        addOnloadHook(check_box_trk_{UUID});
        addOnloadHook(radio_set_image_{UUID});
        
        // ]]>
      </script>
      <!-- BEGIN show_error -->
      <div class="error-box">
        <b>The following error occurred while <!-- BEGIN mode_is_create -->creating the link<!-- BEGINELSE mode_is_create -->saving your changes<!-- END mode_is_create -->:</b><br />
        {ERROR_MESSAGE}
      </div>
      <!-- END show_error -->
      <form action="{FORM_ACTION}" method="post" enctype="multipart/form-data">
        <div class="tblholder">
          <table border="0" cellspacing="1" cellpadding="4">
            <tr>
              <th colspan="2">
                <!-- BEGIN mode_is_create -->
                  Create new link
                <!-- END mode_is_create -->
                <!-- BEGIN mode_is_edit -->
                  Editing link: {LINK_NAME}
                <!-- END mode_is_edit -->
              </th>
            </tr>
            <tr>
              <td class="row1" style="width: 33%;">
                <label for="ln_{UUID}">Link title:</label><br />
                <small>This is only used "internally" for your convenience - the user never sees this value.</small>
              </td>
              <td class="row2">
                <input id="ln_{UUID}" name="link_name" value="{LINK_NAME}" type="text" size="50" />
              </td>
            </tr>
            <tr>
              <td class="row1" style="width: 33%;">
                Link options:
              </td>
              <td class="row2">
                <p><label><input type="checkbox" name="raw_html" id="raw_{UUID}" onclick="check_box_raw_{UUID}();" <!-- BEGIN raw_html -->checked="checked"<!-- END raw_html --> /> Use my own custom HTML here and bypass Linkchomper's processor</label></p>
                <p><label><input type="checkbox" name="track_clicks" id="trk_{UUID}" onclick="check_box_trk_{UUID}();" <!-- BEGIN track_clicks -->checked="checked"<!-- END track_clicks --> /> Track clicks</label></p>
                <p><label><input type="checkbox" name="link_disabled" <!-- BEGIN link_disabled -->checked="checked" <!-- END link_disabled -->/> Link is disabled</label></p>
              </td>
            </tr>
            <tr id="lc_tr_url_{UUID}">
              <td class="row1">
                Link target:<br />
                <small>This should be in the format of http://url.</small>
              </td>
              <td class="row2">
                <input type="text" name="link_target" value="{LINK_TARGET}" size="50" />
              </td>
            </tr>
            <tr id="lc_tr_innerhtml_{UUID}">
              <td class="row1">
                Content inside link:<br />
                <small>You may use HTML here.</small>
              </td>
              <td class="row2">
                Use inside link: <label><input id="is_img_{UUID}" onclick="radio_set_image_{UUID}();" type="radio" name="link_flag_img" value="1" <!-- BEGIN link_flag_image -->checked="checked" <!-- END link_flag_image -->/> Image</label> <label><input type="radio" onclick="radio_set_image_{UUID}();" name="link_flag_img" value="0" <!-- BEGINNOT link_flag_image -->checked="checked" <!-- END link_flag_image -->/> Text or HTML</label>
                <div id="inner_img_{UUID}" style="margin-top: 10px;">
                  Path to image, relative or absolute:<br />
                  <input type="text" size="50" name="link_img_path" value="<!-- BEGIN link_flag_image -->{HTML_INNER}<!-- END link_flag_image -->" onblur="document.getElementById('link_img_preview_{UUID}').src = this.value;" /><br />
                  <br />
                  <img alt=" " src="about:blank" id="link_img_preview_{UUID}" />
                </div>
                <div id="inner_html_{UUID}" style="margin-top: 10px;">
                  {TEXTAREA_HTML_INNER}
                </div>
              </td>
            </tr>
            <tr id="lc_tr_beforehtml_{UUID}">
              <td class="row1">
                Text <u>before</u> link:<br />
                <small>You may use HTML here.</small>
              </td>
              <td class="row2">
                {TEXTAREA_HTML_BEFORE}
              </td>
            </tr>
            <tr id="lc_tr_afterhtml_{UUID}">
              <td class="row1">
                Text <u>after</u> link:<br />
                <small>You may use HTML here.</small>
              </td>
              <td class="row2">
                {TEXTAREA_HTML_AFTER}
              </td>
            </tr>
            <tr>
              <th class="subhead" colspan="2">
                <!-- BEGIN mode_is_create -->
                  <input type="submit" name="stage2[create_new_finish]" value="Create link" />
                <!-- END mode_is_create -->
                <!-- BEGIN mode_is_edit -->
                  <input type="submit" name="stage2[edit_finish]" value="Save changes" />
                <!-- END mode_is_edit -->
                <input type="submit" name="stage2[cancel]" value="Cancel" style="font-weight: normal;" />
              </th>
            </tr>
          </table>
        </div>
        <!-- BEGIN mode_is_edit -->
        <input type="hidden" name="link_id" value="{LINK_ID}" />
        <!-- END mode_is_edit -->
      </form>
      <!-- finish of Linkchomper editor -->
EOF;
      $parser = $template->makeParserText($tpl_code);
    }
    
    $form_action = makeUrlNS('Admin', 'Linkchomper');
    
    $sanitized = array(
        'name' => &$this->link_name,
        'target' => &$this->link_target,
        'inner' => &$this->inner_html,
        'before' => &$this->before_html,
        'after' => &$this->after_html
      );
    
    foreach ( $sanitized as $id => $item )
    {
      unset($sanitized[$id]);
      $sanitized[$id] = htmlspecialchars($item);
    }
    
    $textarea_html_inner  = $template->tinymce_textarea('link_inner_html',  $sanitized['inner'],  10, 60);
    $textarea_html_before = $template->tinymce_textarea('link_before_html', $sanitized['before'], 10, 60);
    $textarea_html_after  = $template->tinymce_textarea('link_after_html',  $sanitized['after'],  10, 60);
    
    if ( $this->mode == LC_EDIT )
    {
      $parser->assign_vars(array(
          'LINK_ID' => $this->link_id
        ));
    }
    
    $parser->assign_vars(array(
        'UUID' => $this->uuid,
        'FORM_ACTION' => $form_action,
        'LINK_NAME' => $sanitized['name'],
        'LINK_TARGET' => $sanitized['target'],
        'HTML_INNER' => $sanitized['inner'],
        'HTML_BEFORE' => $sanitized['before'],
        'HTML_AFTER' => $sanitized['after'],
        'TEXTAREA_HTML_INNER' => $textarea_html_inner,
        'TEXTAREA_HTML_BEFORE' => $textarea_html_before,
        'TEXTAREA_HTML_AFTER' => $textarea_html_after,
        'ERROR_MESSAGE' => strval($this->error)
      ));
    $parser->assign_bool(array(
        'raw_html' => $this->raw_html,
        'track_clicks' => $this->track_clicks,
        'mode_is_create' => ( $this->mode == LC_CREATE ),
        'mode_is_edit' => ( $this->mode == LC_EDIT ),
        'show_error' => ( !empty($this->error) ),
        'link_flag_image' => $this->link_flag_image,
        'link_disabled' => $this->link_disabled
      ));
    
    $html = $parser->run();
    
    return $html;
    
  }
  
  /**
   * For convenience. Echoes out HTML.
   */
  
  function echo_html()
  {
    echo $this->get_html();
  }
  
}

?>
