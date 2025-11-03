<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config_mongo.php';

use MongoDB\Client;

// Verifica que el config se haya cargado bien
if (!is_array($config)) {
    die("❌ Error: config_mongo.php no devolvió un arreglo. Valor devuelto: " . var_export($config, true));
}

try {
    $client = new Client($config['uri']);
    $databaseName = $config['db'];
    $collectionName = $config['collection'];

    $collection = $client->$databaseName->$collectionName;

    // Test de inserción
    $collection->insertOne([
        'test' => 'ok',
        'fecha' => date('c')
    ]);

    echo "✅ Conexión exitosa a MongoDB y documento insertado correctamente en <b>{$databaseName}.{$collectionName}</b>";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
