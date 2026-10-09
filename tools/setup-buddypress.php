<?php
// WP-CLI eval-file evaluates this helper inside its runner scope.
if(!function_exists('buddypress')){throw new RuntimeException('Activate BuddyPress for compatibility tests.');}
$components=(array)bp_get_option('bp-active-components',array());
foreach(array('groups','members','activity') as $component){$components[$component]=1;}
bp_update_option('bp-active-components',$components);
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once buddypress()->plugin_dir.'bp-core/admin/bp-core-admin-schema.php';
bp_core_install($components);
