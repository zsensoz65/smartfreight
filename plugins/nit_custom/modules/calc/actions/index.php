<?php
header('Access-Control-Allow-Origin: *');
require('plugins/nit_custom/classes/nit_custom.php');
switch ($app_module_action) {

    case 'get_item_data':
        $ret = nitcustom::get_item_data();
        print(json_encode($ret));
        exit();
    
    case 'send':
        $alerts->add(db_prepare_input($_POST['message']));
        redirect_to('hello/my_page/index');

    case 'report_form':
        unset($_SESSION['report_success']);
        break;

    case 'calc_inventory':
        $ret = nitcustom::calc_inventory();
        print(json_encode($ret));
        exit();

    case 'loc_name':
        $ret = nitcustom::loc_name();
        print(json_encode($ret));
        exit();

    case 'inventory_report':
        unset($_SESSION['report_success']);
        nitcustom::generate_inventory_report();
        $_SESSION['report_success'] = true;
        break;

    case 'inbound_report':
        unset($_SESSION['report_success']);
        nitcustom::generate_inbound_reports();
        $_SESSION['report_success'] = true;
        break;
        
    case 'convert_unit':
        $ret = nitcustom::convert_unit();
        print(json_encode($ret));
        exit();

    default:
        break;
}
