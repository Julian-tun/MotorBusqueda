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



function getMongoDatabase() {
    global $config;

    if (!is_array($config)) {
        setUltimoErrorMongo('config_mongo.php no devolvió un array válido.');
        return null;
    }

    $uri = $config['uri'] ?? null;
    $dbName = $config['db'] ?? null;

    if (empty($uri) || empty($dbName)) {
        setUltimoErrorMongo('Configuración incompleta de MongoDB. Revisa MONGO_URI y MONGO_DB.');
        return null;
    }

    try {
        $client = new Client($uri);
        return $client->selectDatabase($dbName);
    } catch (Exception $e) {
        setUltimoErrorMongo('Error de conexión a MongoDB: ' . $e->getMessage());
        return null;
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado en MongoDB: ' . $e->getMessage());
        return null;
    }
}

function getMongoTextosCollection() {
    global $config;
    $db = getMongoDatabase();

    if (!$db) {
        return null;
    }

    $collectionName = getenv('MONGO_TEXT_COLLECTION');
    if ($collectionName === false || trim($collectionName) === '') {
        $collectionName = $config['text_collection'] ?? 'textos_articulos';
    }

    return $db->selectCollection(trim($collectionName));
}

function limpiarTextoMongo($value, $maxChars = 0) {
    $value = (string)$value;
    $value = str_replace(["\0"], '', $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value);
    $value = preg_replace('/\n{4,}/u', "\n\n\n", $value);
    $value = trim($value);

    if ($maxChars > 0 && mb_strlen($value, 'UTF-8') > $maxChars) {
        $value = mb_substr($value, 0, $maxChars, 'UTF-8');
    }

    return $value;
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

function guardarTextoArticulo($paperId, $titulo, $textoCompleto, $textoContexto = '', $origen = 'desconocido') {
    $paperId = trim((string)$paperId);
    $titulo = trim((string)$titulo);
    $textoCompleto = limpiarTextoMongo($textoCompleto, 180000);
    $textoContexto = limpiarTextoMongo($textoContexto, 50000);
    $origen = trim((string)$origen);

    if ($paperId === '') {
        setUltimoErrorMongo('No se puede guardar texto del artículo: paperId vacío.');
        return false;
    }

    if ($textoCompleto === '' && $textoContexto === '') {
        setUltimoErrorMongo('No se puede guardar texto del artículo: texto vacío.');
        return false;
    }

    $col = getMongoTextosCollection();
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
                    'textoCompleto' => $textoCompleto,
                    'textoContexto' => $textoContexto,
                    'origen' => $origen,
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
        setUltimoErrorMongo('Error al guardar texto del artículo: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado al guardar texto del artículo: ' . $e->getMessage());
        return false;
    }
}

function obtenerTextoArticulo($paperId) {
    $paperId = trim((string)$paperId);
    $col = getMongoTextosCollection();

    if (!$col || $paperId === '') {
        return null;
    }

    try {
        return $col->findOne(['paperId' => $paperId]);
    } catch (Exception $e) {
        setUltimoErrorMongo('Error al obtener texto del artículo: ' . $e->getMessage());
        return null;
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado al obtener texto del artículo: ' . $e->getMessage());
        return null;
    }
}

function listarResumenesCache($limit = 80, $search = '') {
    $col = getMongoCollection();
    if (!$col) {
        return [];
    }

    $filter = [];
    $search = trim((string)$search);
    if ($search !== '') {
        $filter = ['titulo' => ['$regex' => preg_quote($search, '/'), '$options' => 'i']];
    }

    try {
        return $col->find($filter, [
            'sort' => ['updated_at' => -1, 'created_at' => -1, 'fecha_generacion' => -1],
            'limit' => max(1, min(200, (int)$limit))
        ])->toArray();
    } catch (Exception $e) {
        setUltimoErrorMongo('Error al listar biblioteca: ' . $e->getMessage());
        return [];
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado al listar biblioteca: ' . $e->getMessage());
        return [];
    }
}

function obtenerResumenesPorIds(array $paperIds) {
    $paperIds = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $paperIds)))));
    if (empty($paperIds)) {
        return [];
    }

    $col = getMongoCollection();
    if (!$col) {
        return [];
    }

    try {
        return $col->find(['paperId' => ['$in' => $paperIds]])->toArray();
    } catch (Exception $e) {
        setUltimoErrorMongo('Error al obtener resúmenes seleccionados: ' . $e->getMessage());
        return [];
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado al obtener resúmenes seleccionados: ' . $e->getMessage());
        return [];
    }
}

function eliminarResumenCache($paperId) {
    $paperId = trim((string)$paperId);
    if ($paperId === '') {
        return false;
    }

    $okResumen = false;
    $okTexto = true;
    $colResumen = getMongoCollection();
    $colTexto = getMongoTextosCollection();

    try {
        if ($colResumen) {
            $res = $colResumen->deleteOne(['paperId' => $paperId]);
            $okResumen = $res->getDeletedCount() > 0;
        }
        if ($colTexto) {
            $colTexto->deleteOne(['paperId' => $paperId]);
        }
        return $okResumen && $okTexto;
    } catch (Exception $e) {
        setUltimoErrorMongo('Error al eliminar resumen: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        setUltimoErrorMongo('Error inesperado al eliminar resumen: ' . $e->getMessage());
        return false;
    }
}

