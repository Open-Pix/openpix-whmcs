# Como Integrar WHMCS com a OpenPIX

Certifique-se de ter sua chave de API da OpenPix. Você pode obtê-la no painel de administração da OpenPix.
- **Endpoint para criar cobranças**: `https://api.openpix.com.br/api/v1/charge`
- **Token de autorização**: Insira seu token na configuração do módulo.

## Instalação
1. Acesse o painel administrativo do WHMCS e vá até **Configurações > Módulos de Pagamento**.

2. Ative o módulo OpenPix e insira suas credenciais da OpenPix.

3. Configure o webhook no painel da OpenPix apontando para:  
`https://seu-dominio.com/modules/gateways/callback/openpix.php`

# Créditos
https://github.com/ianchamba/openpix-whmcs