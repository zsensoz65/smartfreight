<?php

class api_entities extends api
{
    function get_entities()
    {
        $data = $this->get_tree();
        
        self::response_success($data);
    }
    
    function get_tree($parent_id = 0, $tree = array(), $level = 0, $path = array())
    {               
        $entities_query = db_query("select e.*, eg.name as group_name from app_entities e left join app_entities_groups eg on e.group_id=eg.id  where e.parent_id='" . $parent_id . "' order by eg.sort_order, eg.name, e.sort_order, e.name");
        
        while ($entities = db_fetch_array($entities_query))
        {        
            $tree[] = array(
                'id' => $entities['id'],
                'parent_id' => $entities['parent_id'],
                'group_id' => $entities['group_id'],
                'group_name' => $entities['group_name'],
                'name' => $entities['name'],
                'notes' => $entities['notes'],
                'sort_order' => $entities['sort_order'],
                'level' => $level,
                'path' => $path,
            );

            $tree = $this->get_tree($entities['id'], $tree, $level + 1, array_merge($path, array($entities['id'])));
        }

        return $tree;
    }
}
