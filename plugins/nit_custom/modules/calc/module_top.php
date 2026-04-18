<?php

//check access
if($app_user['group_id'] != 0 && $app_user['group_id'] != 4)
{
  redirect_to('dashboard/access_forbidden');

}