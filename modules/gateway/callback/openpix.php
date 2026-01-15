<?php

set_time_limit(60);
ini_set('max_execution_time', 60);

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

function returnJsonResponse($message, $success = true, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    
    echo json_encode([
        $success ? 'success' : 'error' => true,
        'message' => $message
    ]);
    
    exit;
}

function OpenPixValidateGatewayModule($gatewayModuleName) {
    $gatewayParams = getGatewayVariables($gatewayModuleName);
    
    if (!$gatewayParams['type']) {
        localAPI('LogActivity', ['description' => "PIX Webhook: Módulo de gateway inativo."]);
        returnJsonResponse("Módulo de gateway inativo", false, 400);
    }
    
    return $gatewayParams;
}

function OpenPixValidateApiKey($gatewayParams) {
    $expectedApiKey = trim($gatewayParams['apiKey']);
    $headers = getallheaders();
    $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
    
    $receivedApiKey = '';
    if (!empty($normalizedHeaders['x-openpix-authorization'])) {
        $receivedApiKey = trim($normalizedHeaders['x-openpix-authorization']);
    } elseif (!empty($_GET['authorization'])) {
        $receivedApiKey = trim(filter_var($_GET['authorization'], FILTER_SANITIZE_STRING));
    }

    if (empty($receivedApiKey) || !hash_equals($expectedApiKey, $receivedApiKey)) {
        localAPI('LogActivity', ['description' => "PIX Webhook: Chave API inválida recebida."]);
        returnJsonResponse("Unauthorized", false, 401);
    }
}

function OpenPixDecodeWebhookData() {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $errorMsg = json_last_error_msg();
        localAPI('LogActivity', ['description' => "PIX Webhook: Erro ao decodificar JSON: {$errorMsg}"]);
        returnJsonResponse("Erro ao decodificar JSON", false, 400);
    }
    
    return $input;
}

function OpenPixGetInvoiceIdFromCharge(array $charge) {
    if (isset($charge['additionalInfo']) && is_array($charge['additionalInfo'])) {
        foreach ($charge['additionalInfo'] as $info) {
            if (isset($info['key'], $info['value']) && $info['key'] === 'Invoice') {
                $invoiceId = filter_var($info['value'], FILTER_SANITIZE_NUMBER_INT);
                if ($invoiceId && is_numeric($invoiceId) && $invoiceId > 0) {
                    return (int)$invoiceId;
                }
            }
        }
    }
    return null;
}

function OpenPixValidateInvoiceExists($invoiceId) {
    $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    return $result['result'] === 'success';
}

function OpenPixGetInvoiceStatus($invoiceId) {
    $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    
    if ($result['result'] === 'success') {
        return strtolower($result['status']);
    }
    
    return null;
}

function OpenPixProcessChargeExpired($invoiceId, $gatewayModuleName, $input) {
    $status = OpenPixGetInvoiceStatus($invoiceId);
    
    if ($status === 'paid') {
        localAPI('LogActivity', ['description' => "PIX Webhook: Fatura #{$invoiceId} já paga. Ignorando expiração."]);
        returnJsonResponse("Fatura já paga, expiração ignorada", true, 200);
    }

    localAPI('UpdateInvoice', [
        'invoiceid' => $invoiceId,
        'status' => 'Cancelled'
    ]);
    
    run_hook('InvoiceCancelled', ['invoiceid' => $invoiceId]);
    logTransaction($gatewayModuleName, $input, 'Fatura cancelada por expiração do pagamento PIX');
    localAPI('LogActivity', ['description' => "PIX Webhook: Fatura #{$invoiceId} cancelada por expiração."]);
    
    returnJsonResponse("Fatura cancelada com sucesso", true, 200);
}

function OpenPixProcessChargeCompleted($invoiceId, $charge, $gatewayModuleName, $input) {
    $status = OpenPixGetInvoiceStatus($invoiceId);
    
    if ($status === 'paid') {
        $transactionId = $charge['transactionID'] ?? 'N/A';
        localAPI('LogActivity', ['description' => "PIX Webhook: Fatura #{$invoiceId} já paga. Webhook duplicado ignorado. Transaction ID: {$transactionId}"]);
        logTransaction($gatewayModuleName, $input, 'Webhook duplicado - Fatura já estava paga');
        returnJsonResponse("Fatura já paga anteriormente", true, 200);
    }
    
    $transactionId = $charge['transactionID'] ?? '';
    
    $amountPaid = 0;
    if (isset($input['pix']['value'])) {
        $amountPaid = $input['pix']['value'] / 100;
    } elseif (isset($charge['value'])) {
        $amountPaid = $charge['value'] / 100;
    }
    
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $invoiceTotal = floatval($invoiceData['total'] ?? 0);
    
    $amountToApply = $amountPaid;
    if ($amountPaid > $invoiceTotal) {
        $amountToApply = $invoiceTotal;
        $excess = $amountPaid - $invoiceTotal;
        localAPI('LogActivity', ['description' => "PIX Webhook: Fatura #{$invoiceId} - Cliente pagou R$ {$amountPaid}, valor da fatura R$ {$invoiceTotal}. Excedente de R$ {$excess} ignorado."]);
    }
    
    addInvoicePayment($invoiceId, $transactionId, $amountToApply, 0, $gatewayModuleName);
    logTransaction($gatewayModuleName, $input, "Pagamento PIX confirmado - Pago: R$ {$amountPaid}, Aplicado: R$ {$amountToApply}");
    localAPI('LogActivity', ['description' => "PIX Webhook: Pagamento confirmado - Fatura #{$invoiceId}, Valor aplicado: R$ {$amountToApply}, Transaction: {$transactionId}"]);
    
    returnJsonResponse("Pagamento processado com sucesso", true, 200);
}

function OpenPixProcessOtherEvents($invoiceId, $charge, $gatewayModuleName, $input, $event) {
    $paymentStatus = $charge['status'] ?? 'UNKNOWN';
    
    logTransaction($gatewayModuleName, $input, "Evento {$event} recebido - Status: {$paymentStatus}");
    localAPI('LogActivity', ['description' => "PIX Webhook: Evento {$event} - Fatura #{$invoiceId}, Status: {$paymentStatus}"]);
    
    returnJsonResponse("Evento processado", true, 200);
}

function OpenPixValidateWebhookData($input) {
    if (!is_array($input)) {
        localAPI('LogActivity', ['description' => "PIX Webhook: Payload inválido (não é array)."]);
        returnJsonResponse("Dados inválidos", false, 400);
    }
    
    $event = $input['event'] ?? '';
    $charge = $input['charge'] ?? [];
    
    $allowedEvents = ['OPENPIX:CHARGE_COMPLETED', 'OPENPIX:CHARGE_EXPIRED', 'OPENPIX:CHARGE_FAILED'];
    if (!in_array($event, $allowedEvents)) {
        localAPI('LogActivity', ['description' => "PIX Webhook: Evento não permitido: {$event}"]);
        returnJsonResponse("Evento não permitido", false, 400);
    }
    
    if (!is_array($charge)) {
        localAPI('LogActivity', ['description' => "PIX Webhook: Dados do charge inválidos."]);
        returnJsonResponse("Dados do charge inválidos", false, 400);
    }
    
    $invoiceId = OpenPixGetInvoiceIdFromCharge($charge);
    if (!$invoiceId) {
        localAPI('LogActivity', ['description' => "PIX Webhook: Invoice ID não encontrado no evento {$event}."]);
        returnJsonResponse("Invoice ID não encontrado", false, 400);
    }
    
    if (!OpenPixValidateInvoiceExists($invoiceId)) {
        localAPI('LogActivity', ['description' => "PIX Webhook: Fatura #{$invoiceId} não existe no sistema."]);
        returnJsonResponse("Fatura não encontrada", false, 404);
    }
    
    return [$event, $charge, $invoiceId];
}

function OpenPixLogWebhookProcessing($event, $invoiceId, $charge, $input) {
    $transactionId = $charge['transactionID'] ?? 'N/A';
    $paymentStatus = $charge['status'] ?? 'UNKNOWN';
    
    $amountPaid = 0;
    if (isset($input['pix']['value'])) {
        $amountPaid = $input['pix']['value'] / 100;
    } elseif (isset($charge['value'])) {
        $amountPaid = $charge['value'] / 100;
    }
    
    localAPI('LogActivity', [
        'description' => "PIX Webhook Recebido | Evento: {$event} | Fatura: #{$invoiceId} | Transaction: {$transactionId} | Valor: R$ {$amountPaid} | Status: {$paymentStatus}"
    ]);
}

$gatewayModuleName = 'openpix';

$gatewayParams = OpenPixValidateGatewayModule($gatewayModuleName);
OpenPixValidateApiKey($gatewayParams);

$input = OpenPixDecodeWebhookData();
list($event, $charge, $invoiceId) = OpenPixValidateWebhookData($input);

OpenPixLogWebhookProcessing($event, $invoiceId, $charge, $input);

switch ($event) {
    case 'OPENPIX:CHARGE_EXPIRED':
        OpenPixProcessChargeExpired($invoiceId, $gatewayModuleName, $input);
        break;
        
    case 'OPENPIX:CHARGE_COMPLETED':
        OpenPixProcessChargeCompleted($invoiceId, $charge, $gatewayModuleName, $input);
        break;
        
    default:
        OpenPixProcessOtherEvents($invoiceId, $charge, $gatewayModuleName, $input, $event);
}

?>