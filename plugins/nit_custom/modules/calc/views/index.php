<h1 class="page-title">Custom Report Generator</h1>

<?php


echo '<a class="btn btn-primary" href="/portal/index.php?module=nit_custom/calc/index&action=inventory_report">Generate Inventory Report</a><br/><br/>';
echo '<a class="btn btn-primary" href="/portal/index.php?module=nit_custom/calc/index&action=inbound_report">Populate Reports and Fields for Inbound Load Units and Item List </a>';

echo '<hr>';

if(isset($_SESSION['report_success']))
{
    echo "<h2 style='color:#0000CC' >Report generated successfully.</h2>";
}
