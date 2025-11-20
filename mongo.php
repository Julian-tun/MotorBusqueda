<?php
require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config_mongo.php';

use MongoDB\Client;
use MongoDB\Exception\Exception;

/**
 * Obtiene la colección configurada en MongoDB.
 */
function getMongoCollection() {
    global $config;

    try {
        $client = new Client($config['uri']);
        $db = $client->selectDatabase($config['db']);
        return $db->selectCollection($config['collection']);
    } catch (Exception $e) {
        error_log("❌ Error de conexión a MongoDB: " . $e->getMessage());
        return null;
    }
}

/**
 * Guarda o reemplaza un resumen en la colección de caché.
 */
function guardarResumenCache($paperId, $titulo, $resumen) {
    $col = getMongoCollection();
    if (!$col) {
        error_log("❌ No se pudo obtener la colección de MongoDB.");
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
        error_log("❌ Error al guardar resumen: " . $e->getMessage());
        return false;
    }
}

/**
 * Busca un resumen por ID en la caché de MongoDB.
 */
function obtenerResumenCache($paperId) {
    $col = getMongoCollection();
    if (!$col) {
        error_log("❌ No se pudo obtener la colección de MongoDB.");
        return null;
    }

    try {
        return $col->findOne(['paperId' => $paperId]);
    } catch (Exception $e) {
        error_log("❌ Error al obtener resumen: " . $e->getMessage());
        return null;
    }
}
