<?php

$obj = array();

if(isset($_GET['id']))
{
	$obj = db_find('app_ext_modules',$_GET['id']);
}
