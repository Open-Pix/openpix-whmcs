<?php

if (!defined("WHMCS")) {

    error_log("Erro: Tentativa de acesso direto ao arquivo do gateway.");

    die("This file cannot be accessed directly");

}

use WHMCS\Database\Capsule;

function pix_MetaData() {

    return [

        'DisplayName' => 'PIX Payment Gateway',

        'APIVersion' => '1.1',

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

        "Icon" => [

            "Type" => "System",

            "Value" => "openpix",

        ],

    ];

}

function openpix_link($params) {

    $invoiceId = $params['invoiceid'];

    // Verifica o status da fatura antes de exibir o QR Code

    $invoiceData = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

    if ($invoiceData->status === 'Paid') {

        error_log("Fatura #{$invoiceId} já está paga. Nenhuma ação necessária.");

        return '<p>Esta fatura já está marcada como paga.</p>';

    }

    $amount = str_replace([',', '.'], '', $params['amount']);

    $callbackUrl = $params['systemurl'] . 'modules/gateways/callback/openpix.php?invoiceid=' . $invoiceId;

    // Recuperar o(s) nome(s) do(s) produto(s) da fatura

    $products = Capsule::table('tblinvoiceitems')

        ->where('invoiceid', $invoiceId)

        ->pluck('description')

        ->toArray();

    // Formata o comentário com o nome dos produtos

    $comment = implode(', ', $products);

    // Verificar se já existe um paymentLinkID e código Pix salvos nos novos campos

    if (!empty($invoiceData->paymentLinkID) && !empty($invoiceData->brCode)) {

        error_log("Fatura #{$invoiceId} já possui um QR Code associado.");

        $existingPaymentLinkID = $invoiceData->paymentLinkID;

        $existingBrCode = $invoiceData->brCode;

        $qrCodeUrl = "https://api.openpix.com.br/openpix/charge/brcode/image/{$existingPaymentLinkID}.png?size=1024";

        $brCode = $existingBrCode;

    } else {

        error_log("Gerando novo QR Code para a fatura #{$invoiceId}. Monto (centavos): {$amount}");

        $taxId = '';

        foreach ($params['clientdetails']['customfields'] as $customfield) {

            if ($customfield['id'] == 41) {

                $taxId = $customfield['value'];

                break;

            }

        }

        $data = [

            'correlationID' => $invoiceId,

            'value' => $amount,

            'comment' => $comment,

            'customer' => [

                'name' => $params['clientdetails']['fullname'],

                'taxID' => $taxId,

                'email' => $params['clientdetails']['email'],

                'phone' => $params['clientdetails']['phonenumber'],

            ],

            'additionalInfo' => [

                ['key' => 'Invoice', 'value' => $invoiceId],

                ['key' => 'Order', 'value' => $invoiceId],

            ],

        ];

        error_log("Dados enviados para API Openpix: " . json_encode($data));

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, "https://api.openpix.com.br/api/v1/charge");

        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        curl_setopt($ch, CURLOPT_HTTPHEADER, [

            'Authorization: ' . $params['apiKey'],

            'Content-Type: application/json',

        ]);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if ($response === false) {

            error_log("Erro ao comunicar com a API Openpix: " . curl_error($ch));

        } else {

            error_log("Resposta da API Openpix: " . $response);

        }

        curl_close($ch);

        $responseArray = json_decode($response, true);

        $paymentLinkID = $responseArray['charge']['paymentLinkID'] ?? null;

        $brCode = $responseArray['charge']['brCode'] ?? null;

        if ($paymentLinkID && $brCode) {

            error_log("QR Code gerado com sucesso para a fatura #{$invoiceId}. PaymentLinkID: {$paymentLinkID}");

            $qrCodeUrl = "https://api.openpix.com.br/openpix/charge/brcode/image/{$paymentLinkID}.png?size=300";

            Capsule::table('tblinvoices')->where('id', $invoiceId)->update([

                'paymentLinkID' => $paymentLinkID,

                'brCode' => $brCode,

            ]);

        } else {

            error_log("Erro ao gerar QR Code. Resposta da API: " . json_encode($responseArray));

            $qrCodeUrl = '#';

        }

    }

    run_hook('OpenpixInvoiceGenerated', ['invoiceId' => $invoiceId]);

    error_log("Hook 'OpenpixInvoiceGenerated' executado para a fatura #{$invoiceId}");

    // Construção do HTML com QR Code e estilo ajustado

    $htmlOutput = '<div style="text-align: center; max-width: 300px; margin: auto;">';

    $htmlOutput .= '<p>Escaneie o QR Code abaixo para efetuar o pagamento:</p>';

    $htmlOutput .= '<img src="' . htmlspecialchars($qrCodeUrl) . '" alt="QR Code de pagamento" style="max-width: 100%; width: 200px; height: auto;">';

    $htmlOutput .= '<p>Ou copie o código:</p>';

    $htmlOutput .= '<div style="overflow-wrap: break-word; word-wrap: break-word; white-space: normal; background-color: #f9f9f9; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">';

    $htmlOutput .= '<code>' . htmlspecialchars($brCode ?? 'Erro ao obter código') . '</code>';

    $htmlOutput .= '</div>';

    $htmlOutput .= '</div>';

    return $htmlOutput;

}