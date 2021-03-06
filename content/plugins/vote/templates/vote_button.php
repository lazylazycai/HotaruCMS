<?php
/**
 * Vote Button
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

The following code looks pretty ugly, but it's not quite as confusing as it first appears. Basically, it's in two blocks, one for "vote" and one for "un-vote". The reason it's so bulky is because we want users to be able to change their vote, so after voting, we need to enable the "un-vote" button and vice-versa. This is done by having two copies of the text blocks and switching the display to show or hide them.
*/

//$user_ip = $h->cage->server->testIp('REMOTE_ADDR');

// Determine the status of the post so we can apply different css to top and new vote buttons:
$status = $h->post->status;
if ($status != 'top' && $status != 'new') { $status = 'new'; }  // used on next line to default to a blue button
$vote_button_type = 'vote_color_' . $status;  // for css difference between top and new stories
?>
 
<!-- Vote Button -->
<div class='vote_button'>

<!-- VOTE COUNT -->
<div id='votes_<?php echo $h->post->id; ?>' class='vote_button_top <?php echo $vote_button_type; ?>'><?php echo $h->vars['votesUp']; ?></div>

<!-- VOTE OR UN-VOTE LINK -->
<?php if (($h->currentUser->loggedIn || $h->vars['vote_anon_vote']) && !$h->vars['voted']) { ?>
    <!-- Logged in and not voted yet -->
    
    <!-- Shown -->
    <div id='text_vote_<?php echo $h->post->id; ?>' class='vote_button_bottom'>
        <a href="#" onclick="vote( <?php echo $h->post->id; ?>, 10); return false;"><b><?php echo $h->lang["vote_button_vote"]; ?></b></a>
    </div>    
    
    <!-- Hidden -->
    <div id='text_unvote_<?php echo $h->post->id; ?>' class='vote_button_bottom' style="display: none;">
        <a href="#" onclick="vote(<?php echo $h->post->id; ?>, -10); return false;"><?php echo $h->lang["vote_button_unvote"]; ?></a>
    </div>        
    
<?php } elseif (($h->currentUser->loggedIn || $h->vars['vote_anon_vote']) && $h->vars['voted']) { ?>
    <!-- Logged in and already voted -->
    
    <!-- Hidden -->
    <div id='text_vote_<?php echo $h->post->id; ?>' class='vote_button_bottom' style="display: none;">
        <a href="#" onclick="vote(<?php echo $h->post->id; ?>, 10); return false;"><b><?php echo $h->lang["vote_button_vote"]; ?></b></a>
    </div>
    
    <!-- Shown -->
    <div id='text_unvote_<?php echo $h->post->id; ?>' class='vote_button_bottom'>
        <a href="#" onclick="vote(<?php echo $h->post->id; ?>, -10); return false;"><?php echo $h->lang["vote_button_unvote"]; ?></a>
    </div>
    
<?php } else { ?>
    <!-- Need to login -->
    
    <div id='text_login_<?php echo $h->post->id; ?>' class='vote_button_bottom'>
        <a href="<?php echo $h->vars['vote_login_url']; ?>"><?php echo $h->lang["vote_button_vote"]; ?></a>
    </div>
<?php } ?>

</div>
