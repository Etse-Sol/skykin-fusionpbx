<?php
require_once 'resources/require.php';
require_once 'resources/pdo.php';

try {
    $sql = "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'v_xml_cdr'";
    $s = $db->prepare($sql);
    $s->execute();
    $cols = $s->fetchAll(PDO::FETCH_ASSOC);
    echo "v_xml_cdr columns:\n";
    print_r($cols);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
