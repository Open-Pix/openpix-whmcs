<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

$gatewayModuleName = 'openpix';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    error_log("Erro: M¨®dulo de gateway inativo.");
    die("M¨®dulo de gateway inativo");
}

// Recebe o JSON de entrada e decodifica
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Erro ao decodificar JSON no webhook: " . json_last_error_msg());
    die("Erro ao decodificar JSON.");
}

error_log("Dados recebidos no webhook: " . print_r($input, true));

// Verifica se todos os campos necess¨¢rios est0Š0o presentes
if (!isset($input['charge']['correlationID'], $input['charge']['transactionID'], $input['charge']['value'], $input['charge']['status'])) {
    error_log("Erro: Dados incompletos no webhook - Dados recebidos: " . print_r($input, true));
    die("Dados incompletos recebidos.");
}

$invoiceId = $input['charge']['correlationID'];
$transactionId = $input['charge']['transactionID'];
$amountPaid = $input['charge']['value'] / 100;
$paymentStatus = $input['charge']['status'];

error_log("Processando webhook - Invoice ID: $invoiceId, Transaction ID: $transactionId, Amount Paid: $amountPaid, Status: $paymentStatus");

// Processa o pagamento com base no status
if ($paymentStatus === 'COMPLETED') {
    addInvoicePayment($invoiceId, $transactionId, $amountPaid, 0, $gatewayModuleName);
    logTransaction($gatewayModuleName, $input, 'Pagamento confirmado');
    error_log("Pagamento confirmado para fatura ID: {$invoiceId}.");
} else {
    logTransaction($gatewayModuleName, $input, 'Pagamento pendente ou falhou');
    error_log("Pagamento pendente ou falhou para fatura ID: {$invoiceId}. Status: {$paymentStatus}");
}

?>