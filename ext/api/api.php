<?php

class api
{

    private $user;
    private $user_access;

    function __construct()
    {
        $this->user = false;

        $this->user_access = array();
    }

    function request()
    {
        //validate IP address
        $this->check_restriction_by_ip();
        
        if(CFG_LDAP_USE==1 and CFG_USE_LDAP_LOGIN_ONLY==1)
        {
            $this->login_ldap();
        }
        else
        {
            $this->login();
        }

        $action = self::_post('action');
        
        //check admin access
        switch($action)
        {
            case 'get_entities':
            case 'get_fields':
            case 'get_field_choices':
            case 'get_global_lists':
                $this->check_admin_access();
                break;
        }

        switch($action)
        {
            case 'login':                
                self::response_success($this->user);
                break;
            case 'insert':
                $this->action_insert();
                break;
            case 'select':
                $this->action_select();
                break;
            case 'update':
                $this->action_update();
                break;
            case 'delete':
                $this->action_delete();
                break;
            case 'get_entities':
                $api = new api_entities();
                $api->get_entities();
                break;
            case 'get_entity_fields':
                $api = new api_entity_fields();
                $api->get_fields();
                break;
            case 'get_field_choices':
                $api = new api_entity_fields();
                $api->get_field_choices();
                break;
            case 'get_global_lists':
                $api = new api_global_list();
                $api->get_lists();
                break;
            case 'get_global_list_choices':
                $api = new api_global_list();                
                $api->get_list_choices();
                break;
            case 'get_process_buttons':
                $api = new api_processes($this->user);                   
                $api->get_process_buttons();
                break;
            case 'run_process':
                $api = new api_processes($this->user);                   
                $api->run_process();
                break;
            case 'get_users_menu':
                $api = new api_users($this->user);                   
                $api->get_users_menu();
                break;
            case 'get_users_filters_panels':
                $api = new api_users($this->user);                   
                $api->get_users_filters_panels();
                break;
            case 'change_user_password':
                $api = new api_users($this->user);                   
                $api->change_user_password();
                break;
            default:
                self::response_error('action "' . $action . '" not exist');
                break;
        }
    }
    
    function check_restriction_by_ip()
    {      
        if(strlen(trim(CFG_API_ALLOWED_IP)))
        {
            $user_ip = $_SERVER['REMOTE_ADDR']??'';
            
            $allowed_ip = explode(',',CFG_API_ALLOWED_IP);
            $allowed_ip = array_map(function($v){
                return trim($v);
            },$allowed_ip);
            
            if(!in_array($user_ip,$allowed_ip))
            {
                self::response_error('IP "' . $user_ip . '" is not allowed!','ip_not_allowed');
            }
        }
    }

    function action_select()
    {
        global $sql_query_having, $app_user, $app_fields_cache;

        $current_entity_id = (int) self::_post('entity_id');

        $this->check_access($current_entity_id, 'view');

        $reports_id = (isset($_REQUEST['reports_id']) ? (int) $_REQUEST['reports_id'] : false);
        
        $related_entity_id = (isset($_REQUEST['related_entity_id']) ? (int) $_REQUEST['related_entity_id'] : false);
        $related_item_id = (isset($_REQUEST['related_item_id']) ? (int) $_REQUEST['related_item_id'] : false);

        $filters = (isset($_REQUEST['filters']) ? $_REQUEST['filters'] : false);

        $parent_item_id = (isset($_REQUEST['parent_item_id']) ? (int) $_REQUEST['parent_item_id'] : false);

        $select_fields = (isset($_REQUEST['select_fields']) ? $_REQUEST['select_fields'] : '');

        $app_user = $this->user;

        $fields_access_schema = users::get_fields_access_schema($current_entity_id, $this->user['group_id']);
        $current_entity_info = db_find('app_entities', $current_entity_id);
        $entity_cfg = entities::get_cfg($current_entity_id);

        $listing_sql_query_select = '';
        $listing_sql_query = '';
        $listing_sql_query_join = '';
        $listing_sql_query_having = '';
        $sql_query_having = array();

        //prepare forumulas query
        $listing_sql_query_select = fieldtype_formula::prepare_query_select($current_entity_id, $listing_sql_query_select);

        //prepare count of related items in listing
        $listing_sql_query_select = fieldtype_related_records::prepare_query_select($current_entity_id, $listing_sql_query_select);

        //add filters query
        if($reports_id)
        {
            $check_query = db_query("select id from app_reports where id='" . db_input($reports_id) . "' and entities_id='" . db_input($current_entity_id) . "'");
            if(!$check = db_fetch_array($check_query))
            {
                self::response_error('Report ' . $reports_id . ' not found for Entity ' . $current_entity_id);
            }

            $listing_sql_query = reports::add_filters_query($reports_id, $listing_sql_query);

            //prepare having query for formula fields
            if(isset($sql_query_having[$current_entity_id]))
            {
                $listing_sql_query_having = reports::prepare_filters_having_query($sql_query_having[$current_entity_id]);
            }
        }
        
        
        if($related_entity_id and $related_item_id)
        {
            $listing_sql_query .= $this->add_related_records_sql($current_entity_id,$related_entity_id, $related_item_id);
        }

        //include customer filters
        if($filters)
        {
            if(is_array($filters))
            {
                $sql_query = array();
                foreach($filters as $field_id => $field_value)
                {
                    if($field_id == 'id')
                    {
                        $sql_query[] = "e.id in (" . implode(',', array_map(function($v)
                                        {
                                            return (int) $v;
                                        }, explode(',', $field_value))) . ")";
                    }
                    elseif($field_id == 'parent_item_id')
                    {
                        $sql_query[] = "e.parent_item_id in (" . implode(',', array_map(function($v)
                                        {
                                            return (int) $v;
                                        }, explode(',', $field_value))) . ")";
                    }
                    elseif($field_id == 'date_added' or $field_id == 'date_updated')
                    {
                        $field_info_query = db_query("select * from app_fields where type = 'fieldtype_{$field_id}' and entities_id='" . $current_entity_id . "'");
                        $field_info = db_fetch_array($field_info_query);
                        
                        $filters = array(
                                    'fields_id' => $field_info['id'],
                                    'filters_values' => ',' . $field_value,
                                    'filters_condition' => 'filter_by_days',
                                    'type'=>'fieldtype_' . $field_id,
                                );
                        
                        $sql_query = fields_types::reports_query(array('class' => $field_info['type'], 'filters' => $filters, 'entities_id' => $current_entity_id, 'sql_query' => $sql_query, 'prefix' => ''));
                    }
                    else
                    {
                        $field_info_query = db_query("select * from app_fields where id = '" . db_input($field_id) . "'");
                        if($field_info = db_fetch_array($field_info_query))
                        {
                            if(is_array($field_value))
                            {
                                $filters = array(
                                    'fields_id' => $field_info['id'],
                                    'filters_values' => (isset($field_value['value']) ? $field_value['value'] : ''),
                                    'filters_condition' => (!isset($field_value['condition']) ? 'include' : $field_value['condition']),
                                );
                            }
                            else
                            {
                                switch($field_info['type'])
                                {
                                    case 'fieldtype_input_date':
                                    case 'fieldtype_input_date_extra':
                                    case 'fieldtype_input_datetime':
                                        $filters_condition = 'filter_by_days';
                                        $field_value = ',' . $field_value;
                                        break;
                                    default:
                                        $filters_condition = 'include';
                                        break;
                                }
                                $filters = array(
                                    'fields_id' => $field_info['id'],
                                    'filters_values' => $field_value,
                                    'filters_condition' => $filters_condition,
                                );
                            }

                            $filters['type'] = $field_info['type'];

                            //print_r($filters);

                            if($filters['filters_condition'] == 'empty_value')
                            {
                                switch($filters['type'])
                                {
                                    case 'fieldtype_date_added':
                                    case 'fieldtype_input_date':
                                    case 'fieldtype_input_date_extra':
                                    case 'fieldtype_input_datetime':
                                    case 'fieldtype_dropdown':
                                    case 'fieldtype_progress':
                                        $sql_query[] = "field_" . $field_info['id'] . "=0";
                                        break;
                                    default:
                                        $sql_query[] = "length(field_" . $field_info['id'] . ")=0";
                                        break;
                                }
                            }
                            elseif($filters['filters_condition'] == 'not_empty_value')
                            {
                                switch($filters['type'])
                                {
                                    case 'fieldtype_date_added':
                                    case 'fieldtype_input_date':
                                    case 'fieldtype_input_date_extra':
                                    case 'fieldtype_input_datetime':
                                    case 'fieldtype_dropdown':
                                        $sql_query[] = "field_" . $field_info['id'] . ">0";
                                        break;
                                    default:
                                        $sql_query[] = "length(field_" . $field_info['id'] . ")>0";
                                        break;
                                }
                            }
                            elseif(strlen($filters['filters_values']) > 0)
                            {                                
                                if(is_string($filters['filters_values']) and in_array($filters['filters_condition'],['search','search_match']))
                                {   
                                    $obj = [
                                        'fields_id'=>$field_info['id'],
                                        'type'=>$field_info['type'],
                                        'filters_values'=>$filters['filters_values'],
                                        'filters_condition'=> ($filters['filters_condition']=='search_match' ? 'search_type_match':'include')];                                                                        
                                    
                                    $sql_query = reports::add_search_qeury($obj, $field_info['entities_id'], $sql_query);                                    
                                }
                                elseif(method_exists($field_info['type'], 'reports_query'))
                                {
                                    $sql_query = fields_types::reports_query(array('class' => $field_info['type'], 'filters' => $filters, 'entities_id' => $current_entity_id, 'sql_query' => $sql_query, 'prefix' => ''));
                                }
                                elseif(is_string($filters['filters_values']))
                                {
                                    $sql_query[] = "field_" . $field_info['id'] . "='" . db_input($filters['filters_values']) . "'";
                                }
                            }
                        }
                    }
                }

                //add filters queries
                if(count($sql_query) > 0)
                {
                    $listing_sql_query .= ' and (' . implode(' and ', $sql_query) . ')';
                }

                //prepare having query for formula fields
                if(isset($sql_query_having[$current_entity_id]))
                {
                    $listing_sql_query_having = reports::prepare_filters_having_query($sql_query_having[$current_entity_id]);
                }
            }
        }

        //filter items by parent
        if($parent_item_id > 0)
        {
            $listing_sql_query .= " and e.parent_item_id='" . db_input($parent_item_id) . "'";
        }

        //check view assigned only access
        $listing_sql_query = items::add_access_query($current_entity_id, $listing_sql_query);

        //prepare order query
        if($reports_id)
        {
            $reports_info = db_find('app_reports', $reports_id);

            //print_r($reports_info);

            if(strlen($reports_info['listing_order_fields']))
            {
                $info = reports::add_order_query($reports_info['listing_order_fields'], $current_entity_id);

                $listing_sql_query .= $info['listing_sql_query'];
                $listing_sql_query_join .= $info['listing_sql_query_join'];
            }
        }

        //add limit
        if(isset($_REQUEST['limit']))
        {
            if((int) $_REQUEST['limit'] > 0)
            {
                $listing_sql_query .= " limit " . (int) $_REQUEST['limit'];
            }
        }

        //render listing body
        $listing_sql = "select e.* " . $listing_sql_query_select . " from app_entity_" . $current_entity_id . " e " . $listing_sql_query_join . " where e.id>0 " . $listing_sql_query;
        
        $extra_response = [];
        
        //split per page
        if(isset($_REQUEST['rows_per_page']) and $_REQUEST['rows_per_page']>0)
        {
            $listing_split = new split_page($listing_sql, '', 'query_num_rows', (int)$_REQUEST['rows_per_page']);
            $listing_sql = $listing_split -> sql_query;
            
            $extra_response = [
                'page' =>  $listing_split->current_page_number,
                'number_of_rows' => $listing_split->number_of_rows,
                'number_of_pages' => $listing_split->number_of_pages,
            ];
        }

        //echo $listing_sql;
        
        
        //fields to select		
        if(!strlen($select_fields) and strlen($reports_info['fields_in_listing']))
        {
            $select_fields = $reports_info['fields_in_listing'];
        }

        $export_fields = array();
        $fields_query = db_query("select f.*, t.name as tab_name from app_fields f, app_forms_tabs t where f.type not in ('fieldtype_action') and f.entities_id='" . db_input($current_entity_id) . "' " . (strlen($select_fields) ? " and f.id in (" . $select_fields . ")" : "") . " and f.forms_tabs_id=t.id order by t.sort_order, t.name, f.sort_order, f.name");
        while($fields = db_fetch_array($fields_query))
        {
            $export_fields[] = $fields;
        }

        //create items query
        $items_query = db_query($listing_sql, false);
        
        //set default page 1 in no rows_per_page
        if(!isset($_REQUEST['rows_per_page']))
        {
            $extra_response = [
                'page' =>  1,
                'number_of_rows' => db_num_rows($items_query),
                'number_of_pages' => 1,
            ];
        }
        
        $items_array = array();
        while($item = db_fetch_array($items_query))
        {
            $row = array(
                'id' => $item['id'],
                'parent_item_id' => $item['parent_item_id'],
                'date_added' => format_date_time($item['date_added']),
                'date_updated' => format_date_time($item['date_updated']),
                'created_by' => $item['created_by'],
            );


            foreach($export_fields as $field)
            {
                //prepare field value
                $value = items::prepare_field_value_by_type($field, $item);

                $output_options = array('class' => $field['type'],
                    'value' => $value,
                    'field' => $field,
                    'item' => $item,
                    'is_print' => true,
                    'is_export' => true,
                );

                if($field['type'] == 'fieldtype_dropdown_multilevel')
                {
                    $row[$field['id']] = array_merge(array(), fieldtype_dropdown_multilevel::output_listing($output_options, true));
                }
                elseif(in_array($field['type'], array('fieldtype_textarea', 'fieldtype_textarea_wysiwyg', 'fieldtype_todo_list')))
                {
                    $row[$field['id']] = trim(fields_types::output($output_options));
                }
                elseif(in_array($field['type'], array('fieldtype_user_photo')))
                {
                    $row[$field['id']] = $value;
                }
                elseif(in_array($field['type'], fields_types::get_attachments_types()) and strlen($value))
                {
                    if(in_array($field['id'], explode(',', CFG_PUBLIC_ATTACHMENTS)))
                    {
                        $files = [];
                        foreach(explode(',', $value) as $file)
                        {
                            $files[] = str_replace('api/', '', url_for('export/file', 'id=' . $field['id'] . '&path=' . $current_entity_id . '-' . $item['id'] . '&file=' . urlencode($file)));
                        }

                        $row[$field['id']] = implode(',', $files);
                    }
                    else
                    {
                        $row[$field['id']] = $value;
                    }
                }
                else
                {
                    $row[$field['id']] = trim(strip_tags(fields_types::output($output_options)));
                }
            }

            $items_array[] = $row;
        }

        //echo '<pre>';
        //print_r($items_array);

        self::response_success($items_array,$extra_response);
    }
    
    function add_related_records_sql($current_entity_id, $related_entity_id, $related_item_id)
    {
        $sql = '';
        
        $table_info = related_records::get_related_items_table_name($current_entity_id, $related_entity_id);
        
        
        
        if (strlen($table_info['sufix']) > 0)
        {   
            $sql .= " and (e.id in (select entity_" . $current_entity_id  . "_items_id from " . $table_info['table_name'] . " where entity_" . $current_entity_id . $table_info['sufix'] . "_items_id='" . $related_item_id . "')";
            $sql .= " or e.id in (select entity_" . $current_entity_id  . $table_info['sufix'] . "_items_id from " . $table_info['table_name'] . " where entity_" . $current_entity_id . "_items_id='" . $related_item_id . "'))";
        }
        else
        {
            $sql .= " and (e.id in (select entity_" . $current_entity_id . "_items_id from " . $table_info['table_name'] . " where entity_" . $related_entity_id . "_items_id='" . $related_item_id . "'))";
        }
                                                    
        //echo $sql;
        
        return $sql;
    }

    function action_insert()
    {
        global $fieldtype_mysql_query_force, $app_user;

        $app_user = $this->user;

        $entity_id = (int) self::_post('entity_id');

        $entity_info_query = db_query("select * from app_entities where id='" . db_input($entity_id) . "'");
        if(!$entity_info = db_fetch_array($entity_info_query))
        {
            self::response_error('entity ' . $entity_id . ' not found');
        }

        $this->check_access($entity_id, 'create');

        $items = self::_post('items');

        $entity_table = 'app_entity_' . $entity_id;

        if(!is_array($items))
        {
            self::response_error('items is not array');
        }

        //check items size
        if(!count($items))
        {
            self::response_error('items is ampty');
        }

        //if add only one item
        if(!isset($items[0]))
        {
            $items = [0 => $items];
        }

        //print_rr($items);

        $choices_values = new choices_values($entity_id);

        $fields_schema = db_find($entity_table, 0);

        $inserted_items_id = array();

        $unique_fields = fields::get_unique_fields_list($entity_id);
        $unique_fields_warning = [];

        foreach($items as $k => $item)
        {
            $is_unique_item = true;

            $sql_data = array();

            //check parent
            if($entity_info['parent_id'] > 0)
            {
                $this->check_parent_item_id($item, $entity_info['parent_id']);
            }

            foreach($item as $field => $value)
            {

                //special field types
                if(in_array($field, array('created_by', 'parent_item_id', 'group_id', 'firstname', 'lastname', 'email', 'username', 'password')))
                {
                    switch($field)
                    {
                        case 'parent_item_id':
                        case 'created_by':
                            $sql_data[$field] = (int) $value;
                            break;
                        case 'group_id':
                            $sql_data['field_6'] = (int) $value;
                            break;
                        case 'firstname':
                            $sql_data['field_7'] = $value;
                            break;
                        case 'lastname':
                            $sql_data['field_8'] = $value;
                            break;
                        case 'email':
                            $sql_data['field_9'] = $value;
                            break;
                        case 'username':
                            $sql_data['field_12'] = $value;
                            break;
                        case 'password':
                            $hasher = new PasswordHash(11, false);
                            $password = (strlen($value) ? trim($value) : users::get_random_password());
                            $sql_data['password'] = $hasher->HashPassword($password);
                            break;
                    }
                }
                else
                {
                    //check if field name exits
                    if(!isset($fields_schema[$field]))
                    {
                        self::response_error($field . ' not exist in entity ' . $entity_id);
                    }

                    //prepare slq data
                    $field_info_query = db_query("select * from app_fields where entities_id='" . $entity_id . "' and  id='" . (int) str_replace('field_', '', $field) . "'");
                    if($field_info = db_fetch_array($field_info_query))
                    {
                        switch($field_info['type'])
                        {
                            case 'fieldtype_input_date':
                            case 'fieldtype_input_date_extra':
                            case 'fieldtype_input_datetime':
                                $sql_data[$field] = get_date_timestamp($value);
                                break;
                            case 'fieldtype_input_file':
                            case 'fieldtype_attachments':
                            case 'fieldtype_image':
                            case 'fieldtype_image_ajax':    
                                $sql_data[$field] = $this->perpare_attachments($value);
                                break;
                            default:
                                $sql_data[$field] = $value;
                                break;
                        }

                        //check uniques
                        if(in_array($field_info['id'], $unique_fields))
                        {
                            $check_query = db_query("select id from app_entity_{$entity_id} where {$field}='" . $sql_data[$field] . "' limit 1");
                            if($check = db_fetch_array($check_query))
                            {
                                $is_unique_item = false;
                                $unique_fields_warning[] = ['id' => $field_info['id'], 'value' => $sql_data[$field]];
                            }
                        }

                        //prepare choices values for fields with multiple values
                        $options = array(
                            'class' => $field_info['type'],
                            'field' => array('id' => $field_info['id']),
                            'value' => ((is_string($value) and strlen($value)) ? explode(',', $value) : '')
                        );

                        $choices_values->prepare($options);
                    }
                }
            }

            //check if user exist
            if($entity_id == 1)
            {
                $this->check_user_item($sql_data);

                //prepare data
                $sql_data['field_5'] = 1;
                $sql_data['field_13'] = CFG_APP_LANGUAGE;
                $sql_data['field_14'] = CFG_APP_SKIN;
            }

            if(count($sql_data) and $is_unique_item)
            {
                //insert item
                $sql_data['date_added'] = time();
                $sql_data['created_by'] = $app_user['id'];
                db_perform($entity_table, $sql_data);
                $item_id = db_insert_id();

                $inserted_items_id[] = $item_id;

                //insert choices values for fields with multiple values
                $choices_values->process($item_id);

                //autoupdate all field types
                fields_types::update_items_fields($entity_id, $item_id);

                //sending sms
                $modules = new modules('sms');
                $sms = new sms($entity_id, $item_id);
                $sms->send_to = [];
                $sms->send_insert_msg();

                //subscribe
                $modules = new modules('mailing');
                $mailing = new mailing($entity_id, $item_id);
                $mailing->subscribe();

                //email rules
                $email_rules = new email_rules($entity_id, $item_id);
                $email_rules->send_insert_msg();


                //send users notification
                if($entity_id == 1)
                {
                    $this->user_notification($sql_data, $password);
                }
                
                //run actions after item insert
                $processes = new processes($entity_id);
                $processes->run_after_insert($item_id);
            }
        }


        $data = array('id' => implode(',', $inserted_items_id));

        if(count($unique_fields_warning))
        {
            $data['unique_fields_warning'] = $unique_fields_warning;
        }

        self::response_success($data);
    }

    function action_delete()
    {
        $entity_id = (int) self::_post('entity_id');
        $update_by_field = self::_post('delete_by_field');

        $entity_info_query = db_query("select * from app_entities where id='" . db_input($entity_id) . "'");
        if(!$entity_info = db_fetch_array($entity_info_query))
        {
            self::response_error('entity ' . $entity_id . ' not found');
        }

        $entity_table = 'app_entity_' . $entity_id;

        $this->check_access($entity_id, 'delete');

        $update_by_field_id = key($update_by_field);
        $update_by_field_value = current($update_by_field);

        $fields_schema = db_find($entity_table, 0);

        //check if field name exits
        if($update_by_field_id != 'id')
        {
            if(!isset($fields_schema[$update_by_field_id]))
            {
                self::response_error($update_by_field_id . ' not exist in entity ' . $entity_id);
            }
        }

        //print_r($sql_data);
        //echo $update_by_field_id  . ' = ' . $update_by_field_value ;


        if(is_array($update_by_field_value))
        {
            if(!count($update_by_field_value))
                $update_by_field_value[] = 0;

            $where_sql = "{$update_by_field_id} in (" . implode(',', $update_by_field_value) . ")";
        }
        else
        {
            $where_sql = "{$update_by_field_id}='" . db_input($update_by_field_value) . "'";
        }


        $deleted_items_id = [];
        $items_query = db_query("select id from {$entity_table} where " . $where_sql);
        while($items = db_fetch_array($items_query))
        {
            $deleted_items_id[] = $items['id'];
        }

        //delte query
        if(count($deleted_items_id))
        {
            $items_to_delete = items::get_items_to_delete($entity_id, [$entity_id => $deleted_items_id]);

            foreach($items_to_delete as $entities_id => $items_list)
            {
                foreach($items_list as $item_id)
                {
                    items::delete($entities_id, $item_id);
                }
            }
        }

        self::response_success(array('id' => implode(',', $deleted_items_id)));
    }

    function action_update()
    {
        global $fieldtype_mysql_query_force, $app_user;

        $app_user = $this->user;

        $entity_id = (int) self::_post('entity_id');
        $data = self::_post('data');
        $update_by_field = self::_post('update_by_field');

        $entity_info_query = db_query("select * from app_entities where id='" . db_input($entity_id) . "'");
        if(!$entity_info = db_fetch_array($entity_info_query))
        {
            self::response_error('entity ' . $entity_id . ' not found');
        }

        $entity_table = 'app_entity_' . $entity_id;

        $this->check_access($entity_id, 'update');

        $choices_values = new choices_values($entity_id);

        $fields_schema = db_find($entity_table, 0);

        $sql_data = array();

        foreach($data as $field => $value)
        {    
            //update user password
            if($field == 'password' and $entity_id==1)
            {
                $hasher = new PasswordHash(11, false);
                $sql_data['password'] =  $hasher->HashPassword($value);
                continue;
            }
            
            //prepare internal fields id
            if(in_array($field,['created_by','parent_item_id']))
            {               
                $check_query = db_query("select id from app_fields where entities_id='" . $entity_id . "' and type='fieldtype_{$field}'");
                if($check = db_fetch($check_query))
                {
                    $field = $check->id;                    
                }                
            }                    
                    
            //prepare slq data
            $field_info_query = db_query("select * from app_fields where entities_id='" . $entity_id . "' and  id='" . (int) str_replace('field_', '', $field) . "'");
            if($field_info = db_fetch_array($field_info_query))
            {
                switch($field_info['type'])
                {
                    case 'fieldtype_created_by':
                        $sql_data['created_by'] = (int) $value;
                        break;
                    case 'fieldtype_parent_item_id':
                        $sql_data['parent_item_id'] = (int) $value;
                        break;
                    case 'fieldtype_input_date':
                    case 'fieldtype_input_date_extra':
                    case 'fieldtype_input_datetime':
                        $sql_data[$field] = get_date_timestamp($value);
                        break;
                    case 'fieldtype_input_file':
                    case 'fieldtype_attachments':
                    case 'fieldtype_image':
                    case 'fieldtype_image_ajax':
                        $sql_data[$field] = $this->perpare_attachments($value);
                        break;
                    default:
                        $sql_data[$field] = $value;
                        break;
                }

                //prepare choices values for fields with multiple values
                $options = array(
                    'class' => $field_info['type'],
                    'field' => array('id' => $field_info['id']),
                    'value' => (strlen($value) ? explode(',', $value) : '')
                );

                $choices_values->prepare($options);
            }
        }

        $update_by_field_id = key($update_by_field);
        $update_by_field_value = current($update_by_field);

        //check if field name exits
        if($update_by_field_id != 'id')
        {
            if(!isset($fields_schema[$update_by_field_id]))
            {
                self::response_error($update_by_field_id . ' not exist in entity ' . $entity_id);
            }
        }

        //print_r($sql_data);
        //echo $update_by_field_id  . ' = ' . $update_by_field_value ;


        if(is_array($update_by_field_value))
        {
            if(!count($update_by_field_value))
                $update_by_field_value[] = 0;

            $where_sql = "{$update_by_field_id} in (" . implode(',', $update_by_field_value) . ")";
        }
        else
        {
            $where_sql = "{$update_by_field_id}='" . db_input($update_by_field_value) . "'";
        }



        $updated_items_id = [];
        $items_query = db_query("select * from {$entity_table} where " . $where_sql);
        while($items = db_fetch_array($items_query))
        {
            $item_id = $items['id'];

            //insert item
            $sql_data['date_updated'] = time();
            db_perform($entity_table, $sql_data, 'update', "id='" . $item_id . "'");

            $updated_items_id[] = $item_id;

            //insert choices values for fields with multiple values
            $choices_values->process($item_id);

            //autoupdate all field types
            fields_types::update_items_fields($entity_id, $item_id);

            $item_info = $items;
            $current_entity_id = $entity_id;

            //sending sms
            $modules = new modules('sms');
            $sms = new sms($current_entity_id, $item_id);
            $sms->send_to = items::get_send_to($current_entity_id, $item_id, $item_info);
            $sms->send_edit_msg($item_info);

            //subscribe
            $modules = new modules('mailing');
            $mailing = new mailing($current_entity_id, $item_id);
            $mailing->update($item_info);

            //email rules
            $email_rules = new email_rules($current_entity_id, $item_id);
            $email_rules->send_edit_msg($item_info);
            
            //run actions after item update
            $processes = new processes($current_entity_id);
            $processes->run_after_update($item_id);
        }

        self::response_success(array('id' => implode(',', $updated_items_id)));
    }

    function perpare_attachments($attachments)
    {
        
        $files_list = [];                 
        
        if(is_array($attachments))
        {                                    
            //print_rr($attachments);
            //exit();
            
            foreach($attachments as $attachment)
            {
                
                if(isset($attachment['content']) and strlen($attachment['content']))
                {
                    $file = attachments::prepare_filename($attachment['name']);

                    $filename = DIR_WS_ATTACHMENTS . $file['folder'] . '/' . $file['file'];

                    $files_list[] = $file['name'];
                    
                    $content = base64_decode($attachment['content']);
                
                    file_put_contents($filename, $content);

                    attachments::resize($filename);
                }
                else
                {
                    $files_list[] = $attachment['name'];
                }
            }
        }
        elseif(strlen($attachments))
        {            
            foreach(explode(',', $attachments) as $url)
            {
                $url = urldecode($url);
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HEADER, false);
                $data = curl_exec($curl);
                curl_close($curl);

                $file = attachments::prepare_filename(pathinfo($url, PATHINFO_BASENAME));

                $filename = DIR_WS_ATTACHMENTS . $file['folder'] . '/' . $file['file'];

                $files_list[] = $file['name'];

                file_put_contents($filename, $data);

                attachments::resize($filename);
            }           
        }
        
        return implode(',', $files_list);
    }

    function check_parent_item_id($data, $parent_entity_id)
    {
        if(!isset($data['parent_item_id']))
        {
            self::response_error('parent_item_id is required');
        }

        if((int) $data['parent_item_id'] == 0)
        {
            self::response_error('parent_item_id is required');
        }

        $check_query = db_query("select * from app_entity_" . $parent_entity_id . " where id='" . db_input($data['parent_item_id']) . "'");
        if(!$check = db_fetch_array($check_query))
        {
            self::response_error('parent_item_id ' . (int) $data['parent_item_id'] . ' not found!');
        }
    }

    function user_notification($data, $password)
    {
        $is_notify = (isset($_REQUEST['notify']) ? $_REQUEST['notify'] : false);

        if($is_notify)
        {
            $login_url = (isset($_REQUEST['login_url']) ? '<a href="' . $_REQUEST['login_url'] . '">' . $_REQUEST['login_url'] . '</a>' : '');

            $options = array(
                'to' => $data['field_9'],
                'to_name' => users::output_heading_from_item($data),
                'subject' => (strlen(CFG_REGISTRATION_EMAIL_SUBJECT) > 0 ? CFG_REGISTRATION_EMAIL_SUBJECT : TEXT_NEW_USER_DEFAULT_EMAIL_SUBJECT),
                'body' => CFG_REGISTRATION_EMAIL_BODY . '<p><b>' . TEXT_LOGIN_DETAILS . '</b></p><p>' . TEXT_USERNAME . ': ' . $data['field_12'] . '<br>' . TEXT_PASSWORD . ': ' . $password . '</p><p>' . $login_url . '</p>',
                'from' => CFG_EMAIL_ADDRESS_FROM,
                'from_name' => CFG_EMAIL_NAME_FROM);

            users::send_email($options);
        }
    }

    function check_user_item($data)
    {
        //check firstname
        if(!isset($data['field_6']))
        {
            self::response_error('group_id is required');
        }

        if((int) $data['field_6'] == 0)
        {
            self::response_error('group_id is required');
        }

        //check firstname
        if(!isset($data['field_7']))
        {
            self::response_error('firstname is required');
        }

        if(!strlen($data['field_7']))
        {
            self::response_error('firstname is required');
        }

        //check lastname
        if(!isset($data['field_8']))
        {
            self::response_error('lastname is required');
        }

        if(!strlen($data['field_8']))
        {
            self::response_error('lastname is required');
        }

        //check username
        if(!isset($data['field_12']))
        {
            self::response_error('username is required');
        }

        if(!strlen($data['field_12']))
        {
            self::response_error('username is required');
        }

        //check email
        if(!isset($data['field_9']))
        {
            self::response_error('email is required');
        }

        if(!strlen($data['field_9']))
        {
            self::response_error('email is required');
        }

        //check password
        if(!isset($data['password']))
        {
            self::response_error('password is required');
        }

        //check eamil
        if(CFG_ALLOW_REGISTRATION_WITH_THE_SAME_EMAIL == 0)
        {
            $check_query = db_query("select count(*) as total from app_entity_1 where field_9='" . db_input($data['field_9']) . "'");
            $check = db_fetch_array($check_query);
            if($check['total'] > 0)
            {
                self::response_error('User Email already exist!', 'email_exist');
            }
        }

        //check username
        $check_query = db_query("select count(*) as total from app_entity_1 where field_12='" . db_input($data['field_12']) . "'");
        $check = db_fetch_array($check_query);
        if($check['total'] > 0)
        {
            self::response_error('Username already exist!', 'email_exist');
        }
    }

    function check_access($entity_id, $access)
    {
        if($this->user['group_id'] == 0)
            return true;

        $user_access = (isset($this->user_access[$entity_id]) ? $this->user_access[$entity_id] : array());

        if(!in_array($access, $user_access))
        {
            self::response_error('Access denied', 'access_denied');
        }
    }
    
    function check_admin_access()
    {
        if($this->user['group_id'] == 0)
        {
            return true;
        }
        else
        {
            self::response_error('Access denied', 'access_denied');
        }
    }

    static function _post($v)
    {
        if(isset($_REQUEST[$v]))
        {
            return $_REQUEST[$v];
        }
        else
        {
            api::response_error($v . ' is required');
        }
    }
    
    function login_ldap()
    {
        $username = self::_post('username');

        $password = self::_post('password');
        
        $ldap = new ldap_login();

        $user_attr = $ldap->do_ldap_login($username, $password);

        if($user_attr['status'] == true)
        {
            $user_email = $username . '@localhost.com';

            if(strlen($user_attr['email']) > 0)
            {
                $user_email = $user_attr['email'];
            }

            if(strlen($user_attr['name']) > 0)
            {
                $first_name = $user_attr['name'];
            }

            $first_name = (strlen($user_attr['firstname']) ? $user_attr['firstname'] : $first_name);
            $last_name = (strlen($user_attr['lastname']) ? $user_attr['lastname'] : '');
            $group = $user_attr['group'];
            
            $group_id = ($group > 0 ? $group : $ldap_default_group_id);

            $check_query = db_query("select id, field_6, multiple_access_groups from app_entity_1 where field_12='" . db_input($username) . "' ");
            if(!$check = db_fetch_array($check_query))
            {
                $hasher = new PasswordHash(11, false);               

                $sql_data = array('password' => $hasher->HashPassword($password),
                    'field_12' => $username,
                    'field_5' => 1,
                    'field_6' => $group_id,
                    'field_7' => $first_name,
                    'field_8' => $last_name,
                    'field_9' => $user_email,
                    'date_added' => time());

                db_perform('app_entity_1', $sql_data);
                $users_id = db_insert_id();

                if(is_ext_installed())
                {
                    $app_user['id'] = $users_id;
                    $app_user['email'] = $user_email;
                    $app_user['group_id'] = $group_id;

                    //email rules
                    $email_rules = new email_rules(1, $users_id);
                    $email_rules->send_insert_msg();

                    //log changeds            
                    $log = new track_changes(1, $users_id);
                    $log->log_insert();
                }

                if(!strstr($user_email, 'localhost.com'))
                {
                    $options = array('to' => $user_email,
                        'to_name' => $first_name,
                        'subject' => (strlen(CFG_REGISTRATION_EMAIL_SUBJECT) > 0 ? CFG_REGISTRATION_EMAIL_SUBJECT : TEXT_NEW_USER_DEFAULT_EMAIL_SUBJECT),
                        'body' => CFG_REGISTRATION_EMAIL_BODY . '<p><b>' . TEXT_LOGIN_DETAILS . '</b></p><p>' . TEXT_USERNAME . ': ' . $username . '<br></p><p><a href="' . url_for('users/login', '', true) . '">' . url_for('users/login', '', true) . '</a></p>',
                        'from' => CFG_EMAIL_ADDRESS_FROM,
                        'from_name' => CFG_EMAIL_NAME_FROM);

                    users::send_email($options);
                }               

                //login log
                users_login_log::success($username, $users_id);
                
            }
            else
            {
                if($group>0 and $check['field_6']>0 and $check['field_6']!=$group)
                {                                        
                    $sql_data = [
                        'field_6'=>$group_id
                        ];
                    
                    
                    if(strlen($check['multiple_access_groups']) and !in_array($group_id,explode(',',$check['multiple_access_groups'])))
                    {
                        $multiple_access_groups = explode(',',$check['multiple_access_groups']);
                        /*if (($key = array_search($check['field_6'], $multiple_access_groups)) !== false) 
                        {
                            unset($multiple_access_groups[$key]);
                        }*/
                        
                        $multiple_access_groups[] = $group_id;
                        
                        $sql_data['multiple_access_groups'] = implode(',',$multiple_access_groups);
                    }
                    
                    db_perform('app_entity_1', $sql_data,'update',"id={$check['id']}");
                }
                
                $users_id = $check['id'];
                
                users_login_log::success($username, $check['id']);
            }  
            
            
            $user_query = db_query("select * from app_entity_1 where field_12='" . db_input($username) . "' ");
            if($user = db_fetch_array($user_query))
            {
                if($user['field_5'] == 1)
                {                    
                    if(strlen($user['field_10'])>0)
                    {        
                      $file = attachments::parse_filename($user['field_10']);
                      $photo = $file['file_sha1'];
                    }
                    else
                    {
                      $photo = '';
                    } 

                    $this->user = array(
                        'id' => $user['id'],
                        'group_id' => (int) $user['field_6'],
                        'multiple_access_groups' => $user['multiple_access_groups'], 
                        'language' => $user['field_13'],
                        'name' => users::output_heading_from_item($user),
                        'email' => $user['field_9'],
                        'username'=>$user['field_12'],
                        'is_email_verified'=> $user['is_email_verified'],
                        'photo'=>$photo,                        
                    );    
                    
                    //set access
                    if($this->user['group_id'] > 0)
                    {
                        $this->user_access = users::get_users_access_schema($this->user['group_id']);
                    }
                }
                else
                {
                    self::response_error('Your account is not active', 'account_not_active');
                }
            }
        
        }
        else
        {
            self::response_error($user_attr['msg'], 'login_fail');
        }
            
    }

    function login()
    {
        $username = self::_post('username');

        $password = self::_post('password');

        $user_query = db_query("select * from app_entity_1 where field_12='" . db_input($username) . "' ");
        if($user = db_fetch_array($user_query))
        {
            if($user['field_5'] == 1)
            {
                $hasher = new PasswordHash(11, false);

                if($hasher->CheckPassword($password, $user['password']))
                {
                    if(strlen($user['field_10'])>0)
                    {        
                      $file = attachments::parse_filename($user['field_10']);
                      $photo = $file['file_sha1'];
                    }
                    else
                    {
                      $photo = '';
                    } 
                    
                    $this->user = array(
                        'id' => $user['id'],
                        'group_id' => (int) $user['field_6'],
                        'multiple_access_groups' => $user['multiple_access_groups'], 
                        'language' => $user['field_13'],
                        'name' => users::output_heading_from_item($user),
                        'email' => $user['field_9'],
                        'username'=>$user['field_12'],
                        'is_email_verified'=> $user['is_email_verified'],
                        'photo'=>$photo,                        
                    );
                }
            }
            else
            {
                self::response_error('Your account is not active', 'account_not_active');
            }
        }

        if($this->user)
        {
            if($this->user['group_id'] > 0)
            {
                $this->user_access = users::get_users_access_schema($this->user['group_id']);
            }
        }
        else
        {
            self::response_error('No match for Username and/or Password', 'login_fail');
        }
    }

    static function response_error($text, $error_code = '')
    {
        $response = array(
            'status' => 'error',
            'error_code' => $error_code,
            'error_message' => $text,
        );

        die(app_json_encode($response));
    }

    static function response_success($data = array(), $extra_response = [])
    {
        $response = array(
            'status' => 'success',
            'data' => $data,
        );
        
        if(count($extra_response))
        {
            $response = array_merge($response, $extra_response);
        }

        die(app_json_encode($response));
    }

}
