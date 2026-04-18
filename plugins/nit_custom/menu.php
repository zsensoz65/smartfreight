<?php


if($app_user['group_id']==0 || $app_user['group_id']==4)
{
    $app_plugin_menu['reports'][] = array('title'=>TEXT_PLUGIN_INVENTORY_REPORT_GENERATOR,'url'=> '/portal/index.php?module=nit_custom/calc/index&action=report_form');
}

plugins::include_menu('reports');