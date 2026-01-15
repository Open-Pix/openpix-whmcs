<?php
require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';
require_once __DIR__ . '/vendor/autoload.php';

use OpenPix\PhpSdk\Client;

header('Content-Type: application/json');

function OpenPixVerifyValidateCorrelationId() {
    if (empty($_POST['correlationID'])) {
        echo json_encode(['error' => 'correlationID não fornecido']);
        exit;
    }
    
    $correlationID = filter_var($_POST['correlationID'], FILTER_SANITIZE_NUMBER_INT);
    if (!$correlationID || !is_numeric($correlationID)) {
        echo json_encode(['error' => 'correlationID inválido']);
        exit;
    }
    
    return (string) $correlationID;
}

function OpenPixVerifyGetApiKey() {
    $gatewayParams = getGatewayVariables('openpix');
    
    if (empty($gatewayParams['apiKey'])) {
        echo json_encode(['error' => 'API Key não configurada']);
        exit;
    }
    
    return $gatewayParams['apiKey'];
}

function OpenPixVerifyGetChargeStatus($correlationID, $apiKey) {
    try {
        $client = Client::create($apiKey);
        $result = $client->charges()->getOne($correlationID);
        
        return [
            'success' => true,
            'data' => $result
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function OpenPixVerifyExtractStatus($response) {
    if (!$response['success']) {
        return [
            'status' => 'ERROR',
            'error' => $response['error']
        ];
    }
    
    $data = $response['data'];
    
    if (isset($data['charge']['status'])) {
        return ['status' => $data['charge']['status']];
    }
    
    if (isset($data['status'])) {
        return ['status' => $data['status']];
    }
    
    return [
        'status' => 'UNKNOWN',
        'error' => 'Status não encontrado na resposta'
    ];
}

function OpenPixVerifyReturnResponse($result) {
    echo json_encode($result);
    exit;
}

$correlationID = OpenPixVerifyValidateCorrelationId();
$apiKey = OpenPixVerifyGetApiKey();
$response = OpenPixVerifyGetChargeStatus($correlationID, $apiKey);
$result = OpenPixVerifyExtractStatus($response);
OpenPixVerifyReturnResponse($result);
?>