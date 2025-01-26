<?php

if (!defined("WHMCS")) {
    die("Acesso restrito.");
}

use WHMCS\Database\Capsule;

/**
 * Hook para cancelar a fatura na OpenPix quando for cancelada no WHMCS
 */
add_hook('InvoiceCancelled', 1, function ($vars) {
    $invoiceId = $vars['invoiceid'];
    error_log("[OpenPix] Hook 'InvoiceCancelled' acionado para a fatura #{$invoiceId}");

    // Buscar se a fatura realmente pertence ao gateway OpenPix
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->where('paymentmethod', 'openpix') // Garante que só faturas do OpenPix sejam processadas
        ->first();

    if (!$invoice) {
        error_log("[OpenPix] Fatura #{$invoiceId} não pertence ao gateway OpenPix. Nenhuma ação realizada.");
        return;
    }

    // Obter os parâmetros do gateway já descriptografados pelo WHMCS
    $gatewayParams = getGatewayVariables("openpix");

    if (empty($gatewayParams['apiKey'])) {
        error_log("[OpenPix] ERRO: Chave API não encontrada através do WHMCS.");
        logActivity("OpenPix: Erro ao recuperar API Key.");
        return;
    }

    $apiKey = $gatewayParams['apiKey'];
    error_log("[OpenPix] Chave API obtida via getGatewayVariables (parcial): " . substr($apiKey, 0, 6) . "********");

    // ID da cobrança na OpenPix será o próprio invoiceId
    $apiUrl = "https://api.openpix.com.br/api/v1/charge/{$invoiceId}";
    error_log("[OpenPix] URL de cancelamento da OpenPix: {$apiUrl}");

    // Configurar a requisição para cancelar a fatura na OpenPix
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_VERBOSE, true); // Ativa logs detalhados

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log detalhado da resposta da API
    error_log("[OpenPix] Resposta da API OpenPix (HTTP {$httpCode}): " . print_r($response, true));

    if ($curlError) {
        error_log("[OpenPix] ERRO: cURL falhou - {$curlError}");
        logActivity("OpenPix: Erro ao conectar com a API OpenPix: {$curlError}");
        return;
    }

    // Verifica se o cancelamento foi bem-sucedido
    if ($httpCode === 200 || $httpCode === 204) {
        error_log("[OpenPix] Sucesso! Fatura #{$invoiceId} cancelada na OpenPix.");
        logActivity("OpenPix: Fatura {$invoiceId} cancelada com sucesso na OpenPix.");
    } else {
        // Tenta decodificar a resposta para verificar a mensagem de erro
        $responseDecoded = json_decode($response, true);
        $errorMessage = isset($responseDecoded['error']) ? $responseDecoded['error'] : 'Erro desconhecido';

        error_log("[OpenPix] ERRO: Falha ao cancelar a fatura {$invoiceId}. Resposta: " . json_encode($responseDecoded));
        logActivity("OpenPix: Falha ao cancelar a fatura {$invoiceId}. Erro: {$errorMessage}");
    }
});