<?php
// ---- Safety wrapper: only run if ?run=1 is in URL ----
if (!isset($_GET['run']) || $_GET['run'] != '1') {
    echo "<h2>HTS Update</h2>";
    echo "To run the HTS update, visit this page with <code>?run=1</code>.<br>";
    echo "Optional: <code>&dry_run=1</code> for simulation, <code>&dry_run=0</code> for live update.<br>";
    echo "Example: <a href='?run=1&dry_run=1'>Dry Run</a> | <a href='?run=1&dry_run=0'>Live Update</a>";
    return;
}

// ---- Configuration ----
$csv_url = "https://www.usitc.gov/sites/default/files/tata/hts/hts_2025_basic_edition_csv.csv";
$dry_run = !isset($_GET['dry_run']) || $_GET['dry_run'] == '1';

// ---- Increase PHP limits for large dataset ----
set_time_limit(600);    // 10 minutes
ini_set('memory_limit', '512M');

// ---- Include Rukovoditel environment ----
require_once("index.php"); // adjust path if necessary

// ---- Download CSV via cURL to avoid 403 ----
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $csv_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$csv_data = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$csv_data || $http_code != 200) {
    echo "<p style='color:red'>Error: Could not download CSV. HTTP code: $http_code</p>";
    return;
}

// ---- Process CSV ----
$lines = explode("\n", $csv_data);
$updated = [];
$created = [];

foreach ($lines as $line) {
    $fields = str_getcsv($line);
    if (count($fields) < 2) continue;

    $hts_code = trim($fields[0]);
    $description = trim($fields[1]);
    if ($hts_code === "") continue;

    // Check if record exists (entity #50)
    $existing = $this->db->get_record(50, ["code" => $hts_code]);

    if ($existing) {
        if (!$dry_run) {
            $this->db->update_record(50, $existing['id'], ["description" => $description]);
        }
        $updated[] = $hts_code;
    } else {
        if (!$dry_run) {
            $this->db->insert_record(50, ["code" => $hts_code, "description" => $description]);
        }
        $created[] = $hts_code;
    }
}

// ---- Output summary ----
echo "<h2>HTS Update Completed</h2>";
echo "Mode: " . ($dry_run ? "Dry run (no database changes)" : "Live update") . "<br><br>";
echo "Records updated: " . count($updated) . "<br>";
if (count($updated) > 0) echo implode(", ", $updated) . "<br><br>";
echo "Records created: " . count($created) . "<br>";
if (count($created) > 0) echo implode(", ", $created) . "<br><br>";

// ---- Links to re-run ----
echo '<a href="?run=1&dry_run=1">Dry Run</a> | <a href="?run=1&dry_run=0">Live Update</a>';
