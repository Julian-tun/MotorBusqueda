<?php
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Exception\Exception;

$configPath = __DIR__ . '/config_mongo.php';
$config = file_exists($configPath) ? require $configPath : [];
$ultimoErrorMongo = null;

// Variables de entorno para Azure/Docker
$envMongoUri = getenv('MONGO_URI');
$envMongoDb = getenv('MONGO_DB');
$envMongoCollection = getenv('MONGO_COLLECTION');

if ($envMongoUri !== false && trim($envMongoUri) !== '') {
    $config['uri'] = trim($envMongoUri);
}

if ($envMongoDb !== false && trim($envMongoDb) !== '') {
    $config['db'] = trim($envMongoDb);
}

if ($envMongoCollection !== false && trim($envMongoCollection) !== '') {
    $config['collection'] = trim($envMongoCollection);
}

function setUltimoErrorMongo($mensaje) {
    global $ultimoErrorMongo;
    $ultimoErrorMongo = $mensaje;
    error_log($mensaje);
}

function getUltimoErrorMongo() {
    global $ultimoErrorMongo;
    return $ultimoErrorMongo;
}

function getMongoCollection() {
    global $config;

    if (!is_array($config)) {
        setUltimoErrorMongo('config_mongo.php no devolvió un array válido.');
        return null;
    }

    $uri = $config['uri'] ?? null;
    $dbName = $config['db'] ?? null;
    $collectionName = $config['collection'] ?? null;

    if (empty($uri) || empty($dbName) || empty($collectionName)) {
        setUltimoErrorMongo('Configuración incompleta de MongoDB. Revisa MONGO_URI, MONGO_DB y MONGO_COLLECTION.');
        return null;
    }

    try {
        $client = new Client($uri);
        $db = $client->selectDatabase($dbName);
        return $db->selectCollection($collectionName);
    } catch (Exception $e) {
        setUltimoErrorMongo('Error de conexión a MongoDB: ' . $e->getMessage());
        return null;
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado en MongoDB: ' . $e->getMessage());
        return null;
    }
}

function guardarResumenCache($paperId, $titulo, $resumen) {
    $paperId = trim((string)$paperId);
    $titulo = trim((string)$titulo);
    $resumen = trim((string)$resumen);

    if ($paperId === '') {
        setUltimoErrorMongo('No se puede guardar en MongoDB: paperId vacío.');
        return false;
    }

    if ($resumen === '') {
        setUltimoErrorMongo('No se puede guardar en MongoDB: resumen vacío.');
        return false;
    }

    $col = getMongoCollection();

    if (!$col) {
        return false;
    }

    try {
        $col->updateOne(
            ['paperId' => $paperId],
            [
                '$set' => [
                    'paperId' => $paperId,
                    'titulo' => $titulo,
                    'resumen' => $resumen,
                    'fecha_generacion' => date('c'),
                    'updated_at' => new MongoDB\BSON\UTCDateTime()
                ],
                '$setOnInsert' => [
                    'created_at' => new MongoDB\BSON\UTCDateTime()
                ]
            ],
            ['upsert' => true]
        );

        return true;
    } catch (Exception $e) {
        setUltimoErrorMongo('Error al guardar resumen: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado al guardar resumen: ' . $e->getMessage());
        return false;
    }
}

function obtenerResumenCache($paperId) {
    $col = getMongoCollection();

    if (!$col) {
        return null;
    }

    try {
        return $col->findOne(['paperId' => $paperId]);
    } catch (Exception $e) {
        setUltimoErrorMongo('Error al obtener resumen: ' . $e->getMessage());
        return null;
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado al obtener resumen: ' . $e->getMessage());
        return null;
    }
}