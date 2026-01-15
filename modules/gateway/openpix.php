<?php

if (!defined("WHMCS")) {
    localAPI('LogActivity', ['description' => "PIX: Tentativa de acesso direto ao arquivo do gateway."]);
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use OpenPix\PhpSdk\Client;

require_once __DIR__ . '/openpix/vendor/autoload.php';

function pix_MetaData() {
    return [
        'DisplayName' => 'PIX Payment Gateway',
        'APIVersion' => '1.2',
    ];
}

function openpix_config() {
    return [
        "FriendlyName" => [
            "Type" => "System",
            "Value" => "PIX",
        ],
        "apiEndpoint" => [
            "Type" => "System",
            "Value" => "https://api.openpix.com.br/api/v1/charge",
        ],
        "apiKey" => [
            "FriendlyName" => "Chave API",
            "Type" => "password",
            "Size" => "50",
            "Description" => "Chave de acesso à API do PIX.",
        ],
        "taxIdFieldId" => [
            "FriendlyName" => "ID do Campo CPF/CNPJ",
            "Type" => "text",
            "Size" => "10",
            "Default" => "41",
            "Description" => "ID do campo personalizado que contém o CPF/CNPJ do cliente.",
        ],
        "pollingInterval" => [
            "FriendlyName" => "Intervalo de Verificação (segundos)",
            "Type" => "text",
            "Size" => "10",
            "Default" => "5",
            "Description" => "Intervalo em segundos para verificar o status do pagamento.",
        ],
        "maxPollingAttempts" => [
            "FriendlyName" => "Máximo de Tentativas",
            "Type" => "text",
            "Size" => "10",
            "Default" => "360",
            "Description" => "Número máximo de tentativas de verificação (padrão: 30 minutos).",
        ],
        "enableOverdue" => [
            "FriendlyName" => "Habilitar Juros e Multa",
            "Type" => "yesno",
            "Default" => "yes",
            "Description" => "Habilitar cobrança de juros e multa após vencimento.",
        ],
        "daysAfterDueDate" => [
            "FriendlyName" => "Dias após Vencimento",
            "Type" => "text",
            "Size" => "10",
            "Default" => "30",
            "Description" => "Dias após o vencimento que a cobrança ainda pode ser paga.",
        ],
        "interestsValue" => [
            "FriendlyName" => "Juros Diário (pontos base)",
            "Type" => "text",
            "Size" => "10",
            "Default" => "3",
            "Description" => "Juros diários em pontos base. Ex: 3 = 0,03% ao dia.",
        ],
        "finesValue" => [
            "FriendlyName" => "Multa (pontos base)",
            "Type" => "text",
            "Size" => "10",
            "Default" => "200",
            "Description" => "Multa em pontos base aplicada no vencimento. Ex: 200 = 2%.",
        ],
        "enableDiscount" => [
            "FriendlyName" => "Habilitar Desconto Antecipado",
            "Type" => "yesno",
            "Default" => "yes",
            "Description" => "Habilitar desconto para pagamento antes do vencimento.",
        ],
        "discountValue" => [
            "FriendlyName" => "Desconto (pontos base)",
            "Type" => "text",
            "Size" => "10",
            "Default" => "500",
            "Description" => "Desconto em pontos base. Ex: 500 = 5%.",
        ],
        "discountDaysBefore" => [
            "FriendlyName" => "Desconto até X dias antes do vencimento",
            "Type" => "text",
            "Size" => "10",
            "Default" => "1",
            "Description" => "Desconto válido até X dias antes do vencimento. Ex: 1 = desconto até 1 dia antes.",
        ],
        "disableDiscountOnPromo" => [
            "FriendlyName" => "Desativar Desconto em Promoções",
            "Type" => "yesno",
            "Default" => "yes",
            "Description" => "Não aplicar desconto quando a fatura já tiver cupom promocional (PromoHosting ou PromoDomain).",
        ],
        "Icon" => [
            "Type" => "System",
            "Value" => "openpix",
        ],
        "_link" => function($params) {
            if (!defined('WHMCS')) {
                return [];
            }
            return [
                "apiKey" => $params['apiKey'] ?? null,
                "taxIdFieldId" => $params['taxIdFieldId'] ?? 41,
                "pollingInterval" => $params['pollingInterval'] ?? 5,
                "maxPollingAttempts" => $params['maxPollingAttempts'] ?? 360,
                "enableOverdue" => $params['enableOverdue'] ?? 'yes',
                "daysAfterDueDate" => $params['daysAfterDueDate'] ?? 30,
                "interestsValue" => $params['interestsValue'] ?? 3,
                "finesValue" => $params['finesValue'] ?? 200,
                "enableDiscount" => $params['enableDiscount'] ?? 'yes',
                "discountValue" => $params['discountValue'] ?? 500,
                "discountDaysBefore" => $params['discountDaysBefore'] ?? 1,
                "disableDiscountOnPromo" => $params['disableDiscountOnPromo'] ?? 'yes',
            ];
        }
    ];
}

function OpenPixFixEncoding($data) {
    if (is_array($data)) {
        return array_map('OpenPixFixEncoding', $data);
    }
    
    if (is_string($data)) {
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        return mb_convert_encoding($data, 'UTF-8', 'auto');
    }
    
    return $data;
}

function OpenPixGetInvoiceData($invoiceId) {
    $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    
    if ($result['result'] === 'success') {
        return $result;
    }
    
    return null;
}

function OpenPixCheckPaidStatus($invoiceId) {
    $invoiceData = OpenPixGetInvoiceData($invoiceId);
    return $invoiceData && $invoiceData['status'] === 'Paid';
}

function OpenPixGetExistingCharge($invoiceData) {
    $invoiceId = $invoiceData['invoiceid'];
    $result = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    
    if ($result && !empty($result->paymentLinkID) && !empty($result->brCode)) {
        return [
            'paymentLinkID' => $result->paymentLinkID,
            'brCode' => $result->brCode
        ];
    }
    return null;
}

function OpenPixGetCustomerTaxId($clientFields, $taxIdFieldId = 41) {
    foreach ($clientFields as $customfield) {
        if ($customfield['id'] == $taxIdFieldId) {
            return $customfield['value'];
        }
    }
    return '';
}

function OpenPixGetInvoiceProducts($invoiceId) {
    $invoiceData = OpenPixGetInvoiceData($invoiceId);
    if (!$invoiceData || !isset($invoiceData['items']['item'])) {
        return [];
    }
    
    $products = [];
    $items = $invoiceData['items']['item'];
    
    if (!isset($items[0])) {
        $items = [$items];
    }
    
    foreach ($items as $item) {
        $products[] = $item['description'];
    }
    
    return $products;
}

function OpenPixInvoiceHasPromo($invoiceId) {
    $invoiceData = OpenPixGetInvoiceData($invoiceId);
    if (!$invoiceData || !isset($invoiceData['items']['item'])) {
        return false;
    }
    
    $items = $invoiceData['items']['item'];
    
    if (!isset($items[0])) {
        $items = [$items];
    }
    
    foreach ($items as $item) {
        if (isset($item['type']) && ($item['type'] === 'PromoHosting' || $item['type'] === 'PromoDomain')) {
            return true;
        }
    }
    
    return false;
}

function OpenPixCalculateDaysUntilDue($invoiceId) {
    $invoiceData = OpenPixGetInvoiceData($invoiceId);
    if (!$invoiceData || empty($invoiceData['duedate'])) {
        return 3;
    }
    
    $dueDate = new DateTime($invoiceData['duedate']);
    $today = new DateTime('today');
    
    $diff = $today->diff($dueDate);
    $days = (int) $diff->format('%r%a');
    
    if ($days < 1) {
        $days = 1;
    }
    
    return $days;
}

function OpenPixFormatComment($products) {
    $comment = implode(', ', $products);
    $comment = preg_replace('/\x{1F1E7}\x{1F1F7}/u', '', $comment);
    $comment = preg_replace('/\x{1F1FA}\x{1F1F8}/u', '', $comment);
    $comment = preg_replace('/\s+/', ' ', $comment);
    $comment = trim($comment);
    
    if (strlen($comment) > 135) {
        $comment = substr($comment, 0, 135);
    }
    
    return $comment;
}

function OpenPixSendFailureEmail($invoiceId) {
    $invoiceData = OpenPixGetInvoiceData($invoiceId);
    
    $mergeFields = [
        'invoice_id' => $invoiceId,
        'invoice_num' => $invoiceId,
        'client_id' => $invoiceData['userid'] ?? '',
        'invoice_total' => $invoiceData['total'] ?? '',
        'invoice_date' => $invoiceData['date'] ?? '',
        'invoice_duedate' => $invoiceData['duedate'] ?? '',
    ];
    
    $result = localAPI('SendEmail', [
        'messagename' => 'Failure Generate Pix',
        'id' => $invoiceId,
        'customtype' => 'invoice',
        'customvars' => base64_encode(serialize($mergeFields)),
    ]);
    
    if ($result['result'] === 'success') {
        localAPI('LogActivity', ['description' => "PIX: Email de falha enviado para admin - Fatura #{$invoiceId}"]);
    } else {
        localAPI('LogActivity', ['description' => "PIX: Erro ao enviar email de falha para admin - Fatura #{$invoiceId} - " . ($result['message'] ?? 'Erro desconhecido')]);
    }
}

function OpenPixPrepareChargeData($params) {
    $invoiceId = $params['invoiceid'];
    $amount = (int) str_replace([',', '.'], '', $params['amount']);
    $products = OpenPixGetInvoiceProducts($invoiceId);
    $comment = OpenPixFormatComment($products);
    $taxIdFieldId = $params['taxIdFieldId'] ?? 41;
    $taxId = OpenPixGetCustomerTaxId($params['clientdetails']['customfields'], $taxIdFieldId);

    $data = [
        'correlationID' => (string) $invoiceId,
        'value' => $amount,
        'comment' => $comment,
        'customer' => [
            'name' => $params['clientdetails']['fullname'],
            'taxID' => $taxId,
            'email' => $params['clientdetails']['email'],
            'phone' => $params['clientdetails']['phonenumber'],
        ],
        'additionalInfo' => [
            ['key' => 'Invoice', 'value' => (string) $invoiceId],
            ['key' => 'Order', 'value' => (string) $invoiceId],
        ],
    ];

    $enableOverdue = $params['enableOverdue'] ?? 'yes';
    
    if ($enableOverdue === 'on' || $enableOverdue === 'yes' || $enableOverdue === '1') {
        $daysForDueDate = OpenPixCalculateDaysUntilDue($invoiceId);
        $daysAfterDueDate = (int) ($params['daysAfterDueDate'] ?? 30);
        $interestsValue = (int) ($params['interestsValue'] ?? 3);
        $finesValue = (int) ($params['finesValue'] ?? 200);

        $data['type'] = 'OVERDUE';
        $data['daysForDueDate'] = $daysForDueDate;
        $data['daysAfterDueDate'] = $daysAfterDueDate;
        $data['interests'] = [
            'value' => $interestsValue
        ];
        $data['fines'] = [
            'value' => $finesValue
        ];
        
        localAPI('LogActivity', ['description' => "PIX: Fatura #{$invoiceId} - Dias até vencimento: {$daysForDueDate}"]);

        $enableDiscount = $params['enableDiscount'] ?? 'yes';
        $disableDiscountOnPromo = $params['disableDiscountOnPromo'] ?? 'yes';
        
        $hasPromo = OpenPixInvoiceHasPromo($invoiceId);
        $shouldDisableDiscount = ($disableDiscountOnPromo === 'on' || $disableDiscountOnPromo === 'yes' || $disableDiscountOnPromo === '1') && $hasPromo;
        
        if ($shouldDisableDiscount) {
            localAPI('LogActivity', ['description' => "PIX: Fatura #{$invoiceId} possui cupom promocional. Desconto antecipado não será aplicado."]);
        }
        
        if (($enableDiscount === 'on' || $enableDiscount === 'yes' || $enableDiscount === '1') && !$shouldDisableDiscount) {
            $discountValue = (int) ($params['discountValue'] ?? 500);
            $discountDaysBefore = (int) ($params['discountDaysBefore'] ?? 1);
            
            $discountDaysActive = $daysForDueDate - $discountDaysBefore;
            
            if ($discountDaysActive < 1) {
                $discountDaysActive = 1;
            }
            
            localAPI('LogActivity', ['description' => "PIX: Fatura #{$invoiceId} - Desconto ativo por {$discountDaysActive} dias (até {$discountDaysBefore} dia(s) antes do vencimento)"]);

            $data['discountSettings'] = [
                'modality' => 'PERCENTAGE_UNTIL_SPECIFIED_DATE',
                'discountFixedDate' => [
                    [
                        'daysActive' => $discountDaysActive,
                        'value' => $discountValue
                    ]
                ]
            ];
        }
    }
    
    $data = OpenPixFixEncoding($data);
    
    return $data;
}

function OpenPixGetClient($apiKey) {
    return Client::create($apiKey);
}

function OpenPixCreateInvoice($params) {
    $invoiceId = $params['invoiceid'];
    
    if (empty($params['apiKey'])) {
        localAPI('LogActivity', ['description' => "PIX: API Key não fornecida para criação da cobrança."]);
        OpenPixSendFailureEmail($invoiceId);
        return null;
    }
    
    if (empty($invoiceId) || !is_numeric($invoiceId)) {
        localAPI('LogActivity', ['description' => "PIX: Invoice ID inválido para criação da cobrança."]);
        OpenPixSendFailureEmail($invoiceId);
        return null;
    }
    
    $data = OpenPixPrepareChargeData($params);
    
    localAPI('LogActivity', ['description' => "PIX: Dados preparados para SDK: " . json_encode($data, JSON_UNESCAPED_UNICODE)]);

    try {
        $client = OpenPixGetClient($params['apiKey']);
        
        localAPI('LogActivity', ['description' => "PIX: Tentando criar cobrança via SDK OpenPix"]);
        
        $result = $client->charges()->create($data);
        
        localAPI('LogActivity', ['description' => "PIX: Sucesso ao criar cobrança via SDK"]);
        localAPI('LogActivity', ['description' => "PIX: Resposta do SDK: " . json_encode($result, JSON_UNESCAPED_UNICODE)]);
        
        return $result;
        
    } catch (Exception $e) {
        localAPI('LogActivity', ['description' => "PIX: Erro no SDK OpenPix: " . $e->getMessage()]);
        OpenPixSendFailureEmail($invoiceId);
        return null;
    }
}

function OpenPixSaveChargeData($invoiceId, $paymentLinkID, $brCode) {
    Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
        'paymentLinkID' => $paymentLinkID,
        'brCode' => $brCode,
    ]);
    
    run_hook('OpenpixInvoiceGenerated', ['invoiceId' => $invoiceId]);
    localAPI('LogActivity', ['description' => "PIX: Hook 'OpenpixInvoiceGenerated' executado para a fatura #{$invoiceId}"]);
}

function OpenPixProcessNewCharge($params) {
    $invoiceId = $params['invoiceid'];
    $responseArray = OpenPixCreateInvoice($params);
    
    if (!$responseArray) {
        localAPI('LogActivity', ['description' => "PIX: Erro ao gerar QR Code. Resposta da API inválida."]);
        return [
            'paymentLinkID' => null,
            'brCode' => 'Erro ao obter código'
        ];
    }

    $paymentLinkID = $responseArray['charge']['paymentLinkID'] ?? null;
    $brCode = $responseArray['charge']['brCode'] ?? $responseArray['brCode'] ?? null;

    if ($paymentLinkID && $brCode) {
        localAPI('LogActivity', ['description' => "PIX: QR Code gerado com sucesso para a fatura #{$invoiceId}. PaymentLinkID: {$paymentLinkID}"]);
        OpenPixSaveChargeData($invoiceId, $paymentLinkID, $brCode);
        
        return [
            'paymentLinkID' => $paymentLinkID,
            'brCode' => $brCode
        ];
    } else {
        localAPI('LogActivity', ['description' => "PIX: Erro ao gerar QR Code. Resposta da API: " . json_encode($responseArray, JSON_UNESCAPED_UNICODE)]);
        OpenPixSendFailureEmail($invoiceId);
        return [
            'paymentLinkID' => null,
            'brCode' => 'Erro ao obter código'
        ];
    }
}

function OpenPixGenerateHTML($paymentLinkID, $brCode, $invoiceId, $pollingInterval = 5, $maxPollingAttempts = 360) {
    $brCodeEncoded = urlencode($brCode);
    $qrCodeUrl = "modules/gateways/openpix/qrcode.php?code={$brCodeEncoded}&size=300";
    
    return '
<style>
.openpix-container {
    width: 100% !important;
    max-width: 350px !important;
    margin: 0 auto !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif !important;
    background-color: #ffffff !important;
    border-radius: 12px !important;
    padding: 20px !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08) !important;
}

.qrcode-wrapper {
    text-align: center !important;
    background-color: #ffffff !important;
    border-radius: 8px !important;
    padding: 10px !important;
    margin-bottom: 20px !important;
    box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05) !important;
}

.qrcode-wrapper img {
    display: block !important;
    max-width: 100% !important;
    width: auto !important;
    height: auto !important;
    margin: 0 auto !important;
}

.pix-instructions {
    font-size: 14px !important;
    color: #424242 !important;
    text-align: center !important;
    margin-bottom: 15px !important;
    line-height: 1.4 !important;
}

.pix-code {
    margin-bottom: 15px !important;
}

.pix-code textarea {
    width: 100% !important;
    padding: 14px !important;
    border: 1px solid #e0e0e0 !important;
    border-radius: 8px !important;
    background-color: #f9f9f9 !important;
    font-family: monospace !important;
    font-size: 13px !important;
    min-height: 65px !important;
    resize: none !important;
    overflow: auto !important;
    box-sizing: border-box !important;
    margin-bottom: 10px !important;
}

.copy-button {
    width: 100% !important;
    background-color: #0066FF !important;
    color: white !important;
    border: none !important;
    border-radius: 6px !important;
    padding: 10px 12px !important;
    cursor: pointer !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    transition: background-color 0.2s ease !important;
    text-align: center !important;
}

.copy-button:hover {
    background-color: #0052cc !important;
}

.copy-message {
    display: none !important;
    text-align: center !important;
    color: #00a152 !important;
    font-size: 14px !important;
    margin-top: 8px !important;
    font-weight: 500 !important;
}

.payment-info {
    background-color: #f5f9ff !important;
    padding: 12px !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    color: #2c5282 !important;
    border-left: 4px solid #4299e1 !important;
    margin-top: 15px !important;
}

.payment-info p {
    margin: 0 !important;
    line-height: 1.4 !important;
    color: #2c5282 !important;
}

.payment-status {
    margin-top: 15px !important;
    padding: 12px !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    text-align: center !important;
    font-weight: 500 !important;
}

.status-waiting {
    background-color: #FFF8E1 !important;
    color: #F57F17 !important;
    border-left: 4px solid #FFB300 !important;
}

.status-completed {
    background-color: #E8F5E9 !important;
    color: #2E7D32 !important;
    border-left: 4px solid #4CAF50 !important;
}

.status-expired {
    background-color: #FFEBEE !important;
    color: #C62828 !important;
    border-left: 4px solid #EF5350 !important;
}

.status-spinner {
    display: inline-block !important;
    width: 12px !important;
    height: 12px !important;
    border: 2px solid currentColor !important;
    border-right-color: transparent !important;
    border-radius: 50% !important;
    animation: spin 0.75s linear infinite !important;
    margin-right: 8px !important;
    vertical-align: middle !important;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<div class="openpix-container">
    <div class="qrcode-wrapper">
        <img id="qrCodeImage" src="' . htmlspecialchars($qrCodeUrl) . '" alt="QR Code de pagamento">
    </div>
    
    <p class="pix-instructions">Escaneie o QR Code ou copie o código abaixo:</p>
    
    <div class="pix-code">
        <textarea id="pixCode" readonly>' . htmlspecialchars($brCode) . '</textarea>
        <button onclick="copyPixCode()" id="copyBtn" class="copy-button">Copiar Código PIX</button>
    </div>
    
    <div id="copyMessage" class="copy-message">
        Código copiado com sucesso!
    </div>
    
    <div id="paymentStatus" class="payment-status status-waiting">
        <span class="status-spinner"></span> Aguardando pagamento...
    </div>
    
    <div class="payment-info">
        <p><strong>Importante:</strong> O pagamento será confirmado automaticamente após ser processado.</p>
    </div>
</div>

<script>
function copyPixCode() {
    var pixCodeElement = document.getElementById("pixCode");
    pixCodeElement.select();
    document.execCommand("copy");
    
    var copyBtn = document.getElementById("copyBtn");
    var copyMessage = document.getElementById("copyMessage");
    
    copyBtn.innerHTML = "Copiado!";
    copyBtn.style.backgroundColor = "#00a152";
    copyMessage.style.display = "block";
    
    setTimeout(function() {
        copyBtn.innerHTML = "Copiar Código PIX";
        copyBtn.style.backgroundColor = "#0066FF";
        copyMessage.style.display = "none";
    }, 2000);
    
    if (navigator.clipboard) {
        navigator.clipboard.writeText(pixCodeElement.value);
    }
}

var checkCount = 0;
var maxChecks = ' . $maxPollingAttempts . ';
var baseInterval = ' . ($pollingInterval * 1000) . ';
var paymentCompleted = false;
var currentInterval = baseInterval;

function getProgressiveInterval() {
    if (checkCount < 12) {
        return baseInterval;
    } else if (checkCount < 60) {
        return baseInterval * 2;
    } else if (checkCount < 180) {
        return baseInterval * 4;
    } else {
        return baseInterval * 6;
    }
}

function updateStatusDisplay() {
    var statusElement = document.getElementById("paymentStatus");
    var timeElapsed = Math.floor((checkCount * baseInterval) / 1000 / 60);
    
    if (timeElapsed > 0) {
        statusElement.innerHTML = "<span class=\"status-spinner\"></span> Aguardando pagamento... (" + timeElapsed + "min)";
    }
}

function checkPaymentStatus() {
    if (paymentCompleted || checkCount >= maxChecks) {
        if (checkCount >= maxChecks && !paymentCompleted) {
            var statusElement = document.getElementById("paymentStatus");
            statusElement.className = "payment-status status-expired";
            statusElement.innerHTML = "⏰ Tempo limite de verificação atingido. Recarregue a página para continuar.";
        }
        return;
    }
    
    checkCount++;
    currentInterval = getProgressiveInterval();
    
    var statusElement = document.getElementById("paymentStatus");
    var correlationID = "' . $invoiceId . '";
    
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "modules/gateways/openpix/verify.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.timeout = 10000;
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    
                    if (response.status === "COMPLETED") {
                        paymentCompleted = true;
                        statusElement.className = "payment-status status-completed";
                        statusElement.innerHTML = "✅ Pagamento confirmado! Atualizando...";
                        
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                    } 
                    else if (response.status === "ACTIVE") {
                        statusElement.className = "payment-status status-waiting";
                        updateStatusDisplay();
                    } 
                    else {
                        statusElement.className = "payment-status status-expired";
                        statusElement.innerHTML = "⚠️ Pagamento expirado ou cancelado";
                        paymentCompleted = true;
                    }
                } catch (e) {
                    statusElement.innerHTML = "<span class=\"status-spinner\"></span> Erro na verificação. Tentando novamente...";
                }
            } else {
                statusElement.innerHTML = "<span class=\"status-spinner\"></span> Erro de conexão. Tentando novamente...";
            }
            
            if (!paymentCompleted && checkCount < maxChecks) {
                setTimeout(checkPaymentStatus, currentInterval);
            }
        }
    };
    
    xhr.ontimeout = function() {
        if (!paymentCompleted && checkCount < maxChecks) {
            setTimeout(checkPaymentStatus, currentInterval);
        }
    };
    
    xhr.onerror = function() {
        if (!paymentCompleted && checkCount < maxChecks) {
            setTimeout(checkPaymentStatus, currentInterval);
        }
    };
    
    xhr.send("correlationID=" + encodeURIComponent(correlationID));
}

document.addEventListener("DOMContentLoaded", function() {
    setTimeout(checkPaymentStatus, 2000);
});
</script>';
}

function openpix_link($params) {
    $invoiceId = $params['invoiceid'];

    if (OpenPixCheckPaidStatus($invoiceId)) {
        localAPI('LogActivity', ['description' => "PIX: Fatura #{$invoiceId} já está paga. Nenhuma ação necessária."]);
        return '<p>Esta fatura já está marcada como paga.</p>';
    }

    $invoiceData = OpenPixGetInvoiceData($invoiceId);
    $existingCharge = OpenPixGetExistingCharge($invoiceData);

    if ($existingCharge) {
        localAPI('LogActivity', ['description' => "PIX: Fatura #{$invoiceId} já possui um QR Code associado."]);
        $paymentLinkID = $existingCharge['paymentLinkID'];
        $brCode = $existingCharge['brCode'];
    } else {
        $chargeData = OpenPixProcessNewCharge($params);
        $paymentLinkID = $chargeData['paymentLinkID'];
        $brCode = $chargeData['brCode'];
    }

    $pollingInterval = $params['pollingInterval'] ?? 5;
    $maxPollingAttempts = $params['maxPollingAttempts'] ?? 360;

    return OpenPixGenerateHTML($paymentLinkID, $brCode, $invoiceId, $pollingInterval, $maxPollingAttempts);
}
?>