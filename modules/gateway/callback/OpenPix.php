<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

$gatewayModuleName = 'openpix';
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Verifica se o módulo de gateway está ativo
if (!$gatewayParams['type']) {
    error_log("Erro: Módulo de gateway inativo.");
    die("Módulo de gateway inativo");
}

// Recupera o valor da apiKey configurada no openpix_config
$expectedApiKey = $gatewayParams['apiKey'];

// Verifica a presença do header Authorization no webhook
$headers = getallheaders(); // Obtém todos os cabeçalhos enviados no webhook

// Recupera a API Key do cabeçalho
$receivedApiKey = $headers['X-Openpix-Authorization'] ?? '';

if ($receivedApiKey !== $expectedApiKey) {
    error_log("Erro: Webhook recebido com chave API inválida. Chave esperada: {$expectedApiKey}, chave recebida: {$receivedApiKey}");
    die("Unauthorized");
}

// Recebe o JSON de entrada e decodifica
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Erro ao decodificar JSON no webhook: " . json_last_error_msg());
    die("Erro ao decodificar JSON.");
}

error_log("Dados recebidos no webhook: " . print_r($input, true));

if ($input['event'] === 'OPENPIX:CHARGE_EXPIRED') {
    $invoiceId = $input['charge']['correlationID']; // ID da fatura

    // Verifica o status da fatura diretamente no banco de dados
    $result = select_query('tblinvoices', 'status', ['id' => $invoiceId]);
    $data = mysql_fetch_assoc($result);

    if ($data && strtolower($data['status']) === 'paid') {
        error_log("A fatura ID {$invoiceId} já está paga. Nenhuma ação será realizada.");
        die("Fatura já paga.");
    }

    // Atualiza o status da fatura para "Cancelled" no banco de dados
    update_query('tblinvoices', ['status' => 'Cancelled'], ['id' => $invoiceId]);
    
    // Dispara o hook InvoiceCancelled
    run_hook('InvoiceCancelled', [
        'invoiceid' => $invoiceId
    ]);

    logTransaction($gatewayModuleName, $input, 'Fatura cancelada por expiração do pagamento.');
    error_log("Fatura ID {$invoiceId} cancelada devido ao evento OPENPIX:CHARGE_EXPIRED.");
    die("Fatura cancelada com sucesso.");
}

$invoiceId = $input['charge']['correlationID'];
$transactionId = $input['charge']['transactionID'];
$amountPaid = $input['charge']['value'] / 100;
$paymentStatus = $input['charge']['status'];

error_log("Processando webhook - Invoice ID: $invoiceId, Transaction ID: $transactionId, Amount Paid: $amountPaid, Status: $paymentStatus");

// Processa o pagamento com base no status
if ($input['event'] === 'OPENPIX:CHARGE_COMPLETED') {
    addInvoicePayment($invoiceId, $transactionId, $amountPaid, 0, $gatewayModuleName);
    logTransaction($gatewayModuleName, $input, 'Pagamento confirmado');
    error_log("Pagamento confirmado para fatura ID: {$invoiceId}.");
} else {
    logTransaction($gatewayModuleName, $input, 'Pagamento pendente ou falhou');
    error_log("Pagamento pendente ou falhou para fatura ID: {$invoiceId}. Status: {$paymentStatus}");
}

?>
