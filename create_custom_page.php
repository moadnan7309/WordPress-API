<?php
 /*
 Plugin Name:Custom Page
 Description:This is custom plugin for Add Pages purpose.
 Version:1.0.0
 Author:Adnan
 */
function josh_admin_menu()
{
    add_menu_page('Forms','Custom Pages','manage_options','josh-admin-menu','josh_admin_menu_main','dashicons-cart',4);
    add_submenu_page('josh-admin-menu','Archived Submissions','Archive','manage_options','josh-admin-menu-sub-archive','josh_admin_menu_sub_archive');
}
add_action('admin_menu','josh_admin_menu');
function josh_admin_menu_main()
{
    echo '<div class="wrap"><h2>Form Submission</h2>Welcome the Form Submission</div>';
}
function josh_admin_menu_sub_archive()
{
    echo '<div class="wrap">Welcome the Archive Pages</div>';
}

?>