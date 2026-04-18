<?php

chdir(substr(__DIR__,0,-5));

define('IS_CRON',true);

//load core
require('includes/application_core.php');

//include nit_custom plugin
require('plugins/nit_custom/application_top.php');

//load app lang
if(is_file($v = 'includes/languages/' . CFG_APP_LANGUAGE))
{
    require($v);
}

// load nit_custom lang
if(is_file($v = 'plugins/nit_custom/languages/' . CFG_APP_LANGUAGE))
{
    require($v);
}

nitcustom::generate_inventory_report();
nitcustom::generate_inbound_reports();