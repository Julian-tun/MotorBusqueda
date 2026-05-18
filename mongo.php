<?php
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Exception\Exception;

$configPath = __DIR__ . '/config_mongo.php';
$config = file_exists($configPath) ? require $configPath : [];

function getMongoCollection() {
    global $config;

    if (!is_array($config)) {
        error_log('config_mongo.php no devolvió un array válido.');
        return null;
    }

    $uri = $config['uri'] ?? null;
    $dbName = $config['db'] ?? null;
    $collectionName = $config['collection'] ?? null;

    if (empty($uri) || empty($dbName) || empty($collectionName)) {
        error_log('Configuración incompleta de MongoDB. Revisa uri, db y collection.');
        return null;
    }

    try {
        $client = new Client($uri);
        $db = $client->selectDatabase($dbName);
        return $db->selectCollection($collectionName);
    } catch (Exception $e) {
        error_log('Error de conexión a MongoDB: ' . $e->getMessage());
        return null;
    } catch (Throwable $e) {
        error_log('Error inesperado en MongoDB: ' . $e->getMessage());
        return null;
    }
}

function guardarResumenCache($paperId, $titulo, $resumen) {
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
                    'fecha_generacion' => date('c')
                ]
            ],
            ['upsert' => true]
        );

        return true;
    } catch (Exception $e) {
        error_log('Error al guardar resumen: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('Error inesperado al guardar resumen: ' . $e->getMessage());
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
        error_log('Error al obtener resumen: ' . $e->getMessage());
        return null;
    } catch (Throwable $e) {
        error_log('Error inesperado al obtener resumen: ' . $e->getMessage());
        return null;
    }
}
