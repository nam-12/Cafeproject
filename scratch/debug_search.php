<?php
require_once 'e:/xampp/htdocs/cafe/config/init.php';

echo "Character Set: " . $pdo->query("SELECT @@character_set_connection")->fetchColumn() . "\n";
echo "Collation: " . $pdo->query("SELECT @@collation_connection")->fetchColumn() . "\n";

$search = "Cafe";
$stmt = $pdo->prepare("SELECT name FROM products WHERE name LIKE ?");
$stmt->execute(['%' . $search . '%']);
$results = $stmt->fetchAll();
echo "Search results for '{$search}': " . count($results) . "\n";
foreach($results as $r) echo "- " . $r['name'] . "\n";

$search = "Cà phê";
$stmt = $pdo->prepare("SELECT name FROM products WHERE name LIKE ?");
$stmt->execute(['%' . $search . '%']);
$results = $stmt->fetchAll();
echo "Search results for '{$search}': " . count($results) . "\n";
foreach($results as $r) echo "- " . $r['name'] . "\n";
?>
