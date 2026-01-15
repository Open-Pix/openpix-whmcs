<?php
if (!defined("WHMCS")) {
    die("Acesso restrito.");
}

require_once __DIR__ . '/../openpix/vendor/autoload.php';

use OpenPix\PhpSdk\Client;

function OpenPixHooksValidateInvoiceBelongsToGateway($invoiceId) {
    $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    
    if ($result['result'] !== 'success') {
        localAPI('LogActivity', ['description' => "[OpenPix] Fatura #{$invoiceId} não encontrada. Nenhuma ação realizada."]);
        return false;
    }
    
    if ($result['paymentmethod'] !== 'openpix') {
        localAPI('LogActivity', ['description' => "[OpenPix] Fatura #{$invoiceId} não pertence ao gateway OpenPix. Nenhuma ação realizada."]);
        return false;
    }
    
    return true;
}

function OpenPixHooksGetGatewayApiKey() {
    $gatewayParams = getGatewayVariables("openpix");
    
    if (empty($gatewayParams['apiKey'])) {
        localAPI('LogActivity', ['description' => "[OpenPix] ERRO: Chave API não encontrada através do WHMCS."]);
        return null;
    }
    
    $apiKey = $gatewayParams['apiKey'];
    localAPI('LogActivity', ['description' => "[OpenPix] Chave API obtida via getGatewayVariables (parcial): " . substr($apiKey, 0, 8) . "********"]);
    
    return $apiKey;
}

function OpenPixHooksDeleteCharge($invoiceId, $apiKey) {
    try {
        $client = Client::create($apiKey);
        $result = $client->charges()->delete((string) $invoiceId);
        
        localAPI('LogActivity', ['description' => "[OpenPix] Sucesso ao cancelar cobrança via SDK para fatura #{$invoiceId}"]);
        localAPI('LogActivity', ['description' => "[OpenPix] Resposta do SDK: " . json_encode($result, JSON_UNESCAPED_UNICODE)]);
        
        return [
            'success' => true,
            'data' => $result
        ];
        
    } catch (Exception $e) {
        localAPI('LogActivity', ['description' => "[OpenPix] Erro no SDK ao cancelar: " . $e->getMessage()]);
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function OpenPixHooksProcessCancelResponse($invoiceId, $result) {
    if ($result['success']) {
        localAPI('LogActivity', ['description' => "[OpenPix] Fatura #{$invoiceId} cancelada com sucesso na OpenPix."]);
        return true;
    } else {
        localAPI('LogActivity', ['description' => "[OpenPix] ERRO: Falha ao cancelar a fatura #{$invoiceId}. Erro: " . $result['error']]);
        return false;
    }
}

function OpenPixHooksCancelInvoice($invoiceId) {
    localAPI('LogActivity', ['description' => "[OpenPix] Hook 'InvoiceCancelled' acionado para a fatura #{$invoiceId}"]);
    
    if (!OpenPixHooksValidateInvoiceBelongsToGateway($invoiceId)) {
        return;
    }
    
    $apiKey = OpenPixHooksGetGatewayApiKey();
    if (!$apiKey) {
        return;
    }
    
    $result = OpenPixHooksDeleteCharge($invoiceId, $apiKey);
    OpenPixHooksProcessCancelResponse($invoiceId, $result);
}

add_hook('InvoiceCancelled', 1, function ($vars) {
    OpenPixHooksCancelInvoice($vars['invoiceid']);
});
?>