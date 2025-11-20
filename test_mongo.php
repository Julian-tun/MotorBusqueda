<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Exception\Exception;

// Pega aquí tu URI de Atlas directamente para probar
$uri = 'mongodb+srv://juliantunortiz_db_user:LvUDo8TlnxQ4Sqd0@cluster0.fdqifn8.mongodb.net/motorbusqueda_db?retryWrites=true&w=majority';
$databaseName = 'motorbusqueda_db';
$collectionName = 'resumenes_cache';

try {
    $client = new Client($uri);
    $db = $client->selectDatabase($databaseName);
    $collection = $db->selectCollection($collectionName);

    // Inserta un documento de prueba
    $result = $collection->insertOne([
        'test' => 'ok desde Azure',
        'fecha' => date('c')
    ]);

    echo "✅ Conectado a Atlas correctamente.<br>";
    echo "Documento insertado con _id: " . $result->getInsertedId();

} catch (Exception $e) {
    echo "❌ Error de conexión a MongoDB Atlas: " . $e->getMessage();
}

////+pruebaaaaaaaaaaaaaa
