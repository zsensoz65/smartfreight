<?php

class nitcustom
{
    static function get_item_data()
    {
        $query = "SELECT * FROM app_entity_{$_REQUEST['entity_id']} WHERE id = {$_REQUEST['item_id']};";
        $result = db_query($query);
        return array('result' => db_fetch_array($result));
    }
    
    static function loc_name()
    {
        $query = db_query( "SELECT field_281, field_309 field_279, field_280, field_313 FROM app_entity_28 WHERE id = {$_REQUEST['loc_id']} LIMIT 1;");
        $result = db_fetch_array($query);
        return array('loc_name' => "{$result['field_281']}, {$result['field_309']} ({$result['field_279']}{$result['field_280']}), {$result['field_313']}");
    }

    static function calc_inventory()
    {
        $project_id = $_REQUEST['project_id'];
        $warehouse_id = $_REQUEST['warehouse_id'];
        $package_id = $_REQUEST['package_id'];

        $input_query = db_query("SELECT SUM(t1.field_879) as res FROM app_entity_43 as t1 
             INNER JOIN app_entity_42 as t2 ON t1.parent_item_id = t2.id 
             INNER JOIN app_entity_39 as t3 ON t2.parent_item_id = t3.id
           WHERE
             t3.parent_item_id = {$project_id}
             AND FIND_IN_SET('{$warehouse_id}', t3.field_817)
             AND t1.field_877 = {$package_id};");
        $input_info = db_fetch_array($input_query);
        $total_input = (empty($input_info['res']) ? 0 : $input_info['res']);

        $locked_query = db_query("SELECT SUM(field_1016) as res FROM app_entity_54 
             WHERE parent_item_id = {$project_id} 
               AND field_1021 = {$warehouse_id} 
               AND field_1015 = {$package_id}
               AND field_1108 = 421");
        $locked_info = db_fetch_array($locked_query);
        $locked_raw = (empty($locked_info['res']) ? 0 : $locked_info['res']);
        $locked = $total_input - $locked_raw;

        $reserved_query = db_query("SELECT SUM(field_1016) as res FROM app_entity_54 
             WHERE parent_item_id = {$project_id} 
               AND field_1021 = {$warehouse_id} 
               AND field_1015 = {$package_id} 
               AND field_1025 = 421
               AND (field_1108 = 420 OR field_1108 = 0)");
        $reserved_info = db_fetch_array($reserved_query);
        $reserved = $total_input - $locked_raw - (empty($reserved_info['res']) ? 0 : $reserved_info['res']);

        $labeled_query = db_query("SELECT SUM(field_1016) as res FROM app_entity_54 
             WHERE parent_item_id = {$project_id} 
               AND field_1021 = {$warehouse_id} 
               AND field_1015 = {$package_id} 
               AND field_1026 = 421
               AND field_1025 = 421
               AND (field_1108 = 420 OR field_1108 = 0)");
        $labeled_info = db_fetch_array($labeled_query);
        $labeled = $total_input - $locked_raw - (empty($labeled_info['res']) ? 0 : $labeled_info['res']);

        return array('locked' => $locked, 'reserved' => $reserved, 'labeled' => $labeled);
    }

    static function generate_inventory_report()
    {
        $query = db_query("
        SELECT tp.field_1036 as user_id,
               tp.id project_id,
               tp.field_587 as project_ref,
               tp.field_1086 as project_name,
               (SELECT field_242 FROM app_entity_25 WHERE id = tp.field_591) as customer,
               (SELECT CONCAT(field_612, ' ', field_613) FROM app_entity_40 WHERE id = tp.field_630) as main_contact,
               (SELECT field_795 FROM app_entity_41 WHERE id = t_input.warehouse_id) as warehouse,
               (SELECT field_786 FROM app_entity_55 WHERE id = t_input.item_id) as item_no,
               (SELECT field_919 FROM app_entity_55 WHERE id = t_input.item_id) as item_description,
               (SELECT name FROM app_global_lists_choices WHERE id =
                    (SELECT field_922 FROM app_entity_60 WHERE id = t_input.pack_id)) as pack_type,
               (SELECT name FROM app_global_lists_choices WHERE id = t_input.form_id) as pack_form,
               IFNULL(tot_in_packs, 0) - IFNULL(tot_out_packs, 0) as physical_inv_packs,
               IFNULL(tot_in_units, 0) - IFNULL(tot_out_units, 0) as physical_inv_units,
               IFNULL(tot_in_packs, 0) - IFNULL(tot_reserved_packs, 0) as reserved_inv_packs,
               IFNULL(tot_in_units, 0) - IFNULL(tot_reserved_units, 0) as reserved_inv_units,
               IFNULL(tot_in_packs, 0) - IFNULL(tot_labeled_packs, 0) as labeled_inv_units,
               IFNULL(tot_in_units, 0) - IFNULL(tot_labeled_units, 0) as labeled_inv_packs
        FROM app_entity_38 as tp
        
        INNER JOIN (SELECT
               i_ships.parent_item_id as project_id,
               i_ships.field_817 as warehouse_id,
               i_items.field_871 as item_id,
               i_items.field_877 as pack_id,
               i_items.field_878 as form_id,
               SUM(field_879) tot_in_packs,
               SUM(field_879) * i_items.field_1134 as tot_in_units
            FROM app_entity_43 as i_items
            INNER JOIN app_entity_42 as i_loads ON i_items.parent_item_id = i_loads.id
            INNER JOIN app_entity_39 as i_ships ON i_loads.parent_item_id = i_ships.id
            GROUP BY i_ships.parent_item_id, i_ships.field_817, i_items.field_871, i_items.field_877, i_items.field_878
            ) as t_input ON tp.id = t_input.project_id
        
        LEFT JOIN (
            SELECT
                parent_item_id as project_id,
                field_1021 as warehouse_id,
                field_1013 as item_id,
                field_1015 as pack_id,
                field_1018 as form_id,
                SUM(field_1016) as tot_out_packs,
                SUM(field_1016) * field_1138 as tot_out_units
            FROM app_entity_54
            WHERE field_1108 = 421
            GROUP BY parent_item_id, field_1021, field_1013, field_1015, field_1018
        ) as t_output
            ON t_input.project_id = t_output.project_id
                             AND t_input.warehouse_id = t_output.warehouse_id
                             AND t_input.item_id = t_output.item_id
                             AND t_input.pack_id = t_output.pack_id
                             AND t_input.form_id = t_output.form_id
        
        LEFT JOIN (
            SELECT
                parent_item_id as project_id,
                field_1021 as warehouse_id,
                field_1013 as item_id,
                field_1015 as pack_id,
                field_1018 as form_id,
                SUM(field_1016) as tot_reserved_packs,
                SUM(field_1016) * field_1138 as tot_reserved_units
            FROM app_entity_54
            WHERE field_1025 = 421 AND field_1108 = 420
            
            GROUP BY parent_item_id, field_1021, field_1013, field_1015, field_1018
        ) as t_reserved
            ON t_input.project_id = t_reserved.project_id
                 AND t_input.warehouse_id = t_reserved.warehouse_id
                 AND t_input.item_id = t_reserved.item_id
                 AND t_input.pack_id = t_reserved.pack_id
                 AND t_input.form_id = t_reserved.form_id
        
        LEFT JOIN (
            SELECT
                parent_item_id as project_id,
                field_1021 as warehouse_id,
                field_1013 as item_id,
                field_1015 as pack_id,
                field_1018 as form_id,
                SUM(field_1016) as tot_labeled_packs,
                SUM(field_1016) * field_1138 as tot_labeled_units
            FROM app_entity_54
            WHERE field_1026 = 421 AND field_1025 = 421 AND field_1108 = 420
            GROUP BY parent_item_id, field_1021, field_1013, field_1015, field_1018
        ) as t_labeled
            ON t_input.project_id = t_labeled.project_id
                 AND t_input.warehouse_id = t_labeled.warehouse_id
                 AND t_input.item_id = t_labeled.item_id
                 AND t_input.pack_id = t_labeled.pack_id
                 AND t_input.form_id = t_labeled.form_id
        
        GROUP BY tp.id, t_input.warehouse_id, t_input.item_id, t_input.pack_id, t_input.form_id
        ORDER BY tp.id, t_input.warehouse_id, t_input.item_id, t_input.pack_id, t_input.form_id;");

        db_query('TRUNCATE TABLE app_entity_75');
        db_query('TRUNCATE TABLE app_entity_75_values');

        while ($result = db_fetch_array($query)) {

            $data = array(
                'parent_item_id' => $result['project_id'],
                'date_added' => time(),
                'date_updated' => time(),
                'created_by' => '1',
                'sort_order' => 0,
                'field_1214' => $result['project_ref'],
                'field_1215' => $result['project_name'],
                'field_1216' => $result['customer'],
                'field_1217' => $result['main_contact'],
                'field_1218' => $result['warehouse'],
                'field_1219' => $result['item_no'],
                'field_1220' => $result['item_description'],
                'field_1221' => $result['pack_type'],
                'field_1222' => $result['pack_form'],
                'field_1223' => $result['physical_inv_packs'],
                'field_1224' => $result['physical_inv_units'],
                'field_1225' => $result['reserved_inv_packs'],
                'field_1226' => $result['reserved_inv_units'],
                'field_1227' => $result['labeled_inv_units'],
                'field_1228' => $result['labeled_inv_packs'],
                'field_1229' => $result['user_id']
            );
            db_perform('app_entity_75', $data, 'insert');

            $users = explode(',', $result['user_id']);
            for ($i = 0; $i < count($users); $i++) {
                $data2 = array(
                    'items_id' => db_insert_id(),
                    'fields_id' => 1229,
                    'value' => $users[$i]
                );
                db_perform('app_entity_75_values', $data2, 'insert');
            }
        }
    }

    static function generate_inbound_reports()
    {
        // update inbound loading units and items fields
        $query = db_query("SELECT items.id as item_id, ships.field_770 as ship_no, projs.field_587 as proj_no , 
                                        lunits.field_866 as cont_no, lunits.id as lunit_id
                    FROM app_entity_43 as items 
                    INNER JOIN app_entity_42 as lunits ON items.parent_item_id = lunits.id 
                    INNER JOIN app_entity_39 as ships ON lunits.parent_item_id = ships.id
                    INNER JOIN app_entity_38 as projs ON ships.parent_item_id = projs.id");

        while ($result = db_fetch_array($query)) {
            db_query("UPDATE app_entity_43 
                            SET field_1279 = '{$result['ship_no']}', field_1282 = '{$result['proj_no']}', field_875 = '{$result['cont_no']}'
                            WHERE id = {$result['item_id']};");

            db_query("UPDATE app_entity_42 
                            SET field_1278 = '{$result['ship_no']}', field_1281 = '{$result['proj_no']}'
                            WHERE id = {$result['lunit_id']};");
        }

        // inbound loading units report
        $query = db_query("
        SELECT projs.field_1036 as user_id,
               projs.id as project_id,
               projs.field_587 as project_ref,
               (SELECT field_667 FROM app_entity_45 WHERE id = lunits.field_868) as container_type,
               lunits.field_866 as container_no,
               lunits.field_867 as seal1,
               lunits.field_870 as seal2,
               lunits.field_1255 as no_packs,            
               (SELECT name FROM app_global_lists_choices WHERE id = lunits.field_1256) as pack_type,
               lunits.field_1253 as gross_weight,
               (SELECT field_657 FROM app_entity_44 WHERE id = lunits.field_1254) as weight_unit,
               lunits.field_1234 as date_available,
               lunits.field_1235 as get_out_date,
               lunits.field_1236 as return_to_terminal,
               (SELECT GROUP_CONCAT(field_515) FROM app_entity_34 WHERE id IN 
               (SELECT entity_56_items_id FROM app_related_items_42_56 WHERE entity_42_items_id = lunits.id)) as pl_entry,
               lunits.field_1278 as ship_ref_no,
               lunits.field_1281 as proj_ref_no,
               lunits.field_1344 as free_time_ends,
               lunits.field_1347 as term_app_datetime,
               lunits.field_1348 as warehouse_drop_date,
               ships.field_831 as eta_port_ramp,
               ships.field_833 as linebl_no
            FROM app_entity_42 as lunits
            INNER JOIN app_entity_39 as ships ON lunits.parent_item_id = ships.id
            INNER JOIN app_entity_38 as projs ON ships.parent_item_id = projs.id");

        db_query('TRUNCATE TABLE app_entity_76');
        db_query('TRUNCATE TABLE app_entity_76_values');

        while ($result = db_fetch_array($query)) {

            $data = array(
                'parent_item_id' => $result['project_id'],
                'date_added' => time(),
                'date_updated' => time(),
                'created_by' => '1',
                'sort_order' => 0,
                'field_1291' => $result['container_type'],
                'field_1289' => $result['container_no'],
                'field_1290' => $result['seal1'],
                'field_1292' => $result['seal2'],
                'field_1300' => $result['no_packs'],
                'field_1301' => $result['pack_type'],
                'field_1298' => $result['gross_weight'],
                'field_1299' => $result['weight_unit'],
                'field_1295' => $result['date_available'],
                'field_1296' => $result['get_out_date'],
                'field_1297' => $result['return_to_terminal'],
                'field_1293' => $result['pl_entry'],
                'field_1302' => $result['ship_ref_no'],
                'field_1303' => $result['proj_ref_no'],
                'field_1345' => $result['free_time_ends'],
                'field_1349' => $result['eta_port_ramp'],
                'field_1350' => $result['warehouse_drop_date'],
                'field_1351' => $result['term_app_datetime'],
                'field_1363' => $result['linebl_no'],
                'field_1294' => $result['user_id']
            );
            db_perform('app_entity_76', $data, 'insert');

            $users = explode(',', $result['user_id']);
            for ($i = 0; $i < count($users); $i++) {
                $data2 = array(
                    'items_id' => db_insert_id(),
                    'fields_id' => 1294,
                    'value' => $users[$i]
                );
                db_perform('app_entity_76_values', $data2, 'insert');
            }
        }

        // inbound items report
        $query = db_query("
        SELECT projs.field_1036 as user_id,
               projs.id as project_id,
               projs.field_587 as project_ref,
               lunits.field_866 as container_no,
               (SELECT field_786 FROM app_entity_55 WHERE id = items.field_871) as item_no,
               items.field_872 as inbound_po1,
               items.field_873 as inbound_po2,
               items.field_874 as other_ref,            
               (SELECT field_795 FROM app_entity_41 WHERE id = items.field_876) as warehouse,
               (SELECT name FROM app_global_lists_choices WHERE id =
                    (SELECT field_922 FROM app_entity_60 WHERE id = items.field_877)) as pack_type,
               (SELECT name FROM app_global_lists_choices WHERE id = items.field_878) as pack_form,
               items.field_879 as no_packs,   
               items.field_880 as no_units, 
               (SELECT name FROM app_global_lists_choices WHERE id = items.field_1088) as unit_name,
               items.field_882 as no_pallets_calc, 
               items.field_883 as no_pallets_actual, 
               items.field_1133 as item_id,
               items.field_1134 as units_per_box,
               items.field_1135 as boxes_per_pallet,
               items.field_1279 as ship_ref_no,
               items.field_1282 as proj_ref_no,
               lunits.field_1347 as term_app_datetime,
               lunits.field_1348 as warehouse_drop_date,
               ships.field_831 as eta_port_ramp,
               ships.field_833 as linebl_no
            FROM app_entity_43 as items
            INNER JOIN app_entity_42 as lunits ON items.parent_item_id = lunits.id
            INNER JOIN app_entity_39 as ships ON lunits.parent_item_id = ships.id
            INNER JOIN app_entity_38 as projs ON ships.parent_item_id = projs.id");

        db_query('TRUNCATE TABLE app_entity_78');
        db_query('TRUNCATE TABLE app_entity_78_values');

        while ($result = db_fetch_array($query)) {

            $data = array(
                'parent_item_id' => $result['project_id'],
                'date_added' => time(),
                'date_updated' => time(),
                'created_by' => '1',
                'sort_order' => 0,
                'field_1320' => $result['container_no'],
                'field_1316' => $result['item_no'],
                'field_1317' => $result['inbound_po1'],
                'field_1318' => $result['inbound_po2'],
                'field_1319' => $result['other_ref'],
                'field_1321' => $result['warehouse'],
                'field_1322' => $result['pack_type'],
                'field_1323' => $result['pack_form'],
                'field_1324' => $result['no_packs'],
                'field_1325' => $result['no_units'],
                'field_1328' => $result['unit_name'],
                'field_1326' => $result['no_pallets_calc'],
                'field_1327' => $result['no_pallets_actual'],
                'field_1329' => $result['item_id'],
                'field_1330' => $result['units_per_box'],
                'field_1331' => $result['boxes_per_pallet'],
                'field_1334' => $result['ship_ref_no'],
                'field_1335' => $result['proj_ref_no'],
                'field_1355' => $result['eta_port_ramp'],
                'field_1356' => $result['warehouse_drop_date'],
                'field_1357' => $result['term_app_datetime'],
                'field_1364' => $result['linebl_no'],
                'field_1332' => $result['user_id'],
            );
            db_perform('app_entity_78', $data, 'insert');

            $users = explode(',', $result['user_id']);
            for ($i = 0; $i < count($users); $i++) {
                $data2 = array(
                    'items_id' => db_insert_id(),
                    'fields_id' => 1332,
                    'value' => $users[$i]
                );
                db_perform('app_entity_78_values', $data2, 'insert');
            }
        }
    }
    
    public static function convert_unit()
    {
        $origin_unit_id = $_REQUEST['origin_unit_id'];
        $origin_amount = floatval($_REQUEST['origin_amount']);
        $destination_unit_id = $_REQUEST['destination_unit_id'];

        $query = "SELECT field_2201 FROM app_entity_104 WHERE parent_item_id = {$origin_unit_id} AND field_2200 = {$destination_unit_id};";
        $result = db_query($query);
        $record = array(db_fetch_array($result));
        $conversion_rate = $record[0]['field_2201'];
        $converted_amount = (is_numeric($origin_amount) && count($record) > 0 ? ($conversion_rate * $origin_amount) : null);
        return array("converted_amount" => $converted_amount, "converted_unit_id" => $destination_unit_id);
    }
}
