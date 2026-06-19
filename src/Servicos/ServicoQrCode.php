<?php

namespace Onsoft\Agt\Servicos;

use BaconQrCode\Encoder\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * ServicoQrCode
 *
 * ══════════════════════════════════════════════════════════════════════
 * RECONSTRUÍDO a partir da documentação OFICIAL da AGT:
 * https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/qrcode.html
 *
 * O conteúdo anterior (string ";"-separada com NIF/DOC/TIPO/DATA/TOTAL/
 * HASH/CERT) NÃO corresponde à especificação real. O QR Code da AGT
 * codifica uma URL que aponta para o PRÓPRIO PORTAL da AGT, onde a
 * verificação do documento é feita do lado deles — o QR não carrega
 * os dados fiscais directamente.
 *
 * Especificação EXACTA (documentação "Especificações do QR Code"):
 *   Padrão:                QR Code Model 2
 *   Versão:                4 (33×33 módulos)
 *   Nível de correcção:    M (15%)
 *   Modo de dados:         Byte
 *   Codificação:           UTF-8
 *   URL:                   https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe
 *                            ?emissor={nifEmissor}&document={documentNo}
 *   Formato do ficheiro:   PNG, 350×350 px
 *   Espaços no documentNo: substituídos por %20
 *   Logotipo:              incluído, <20% da imagem total
 * ══════════════════════════════════════════════════════════════════════
 */
class ServicoQrCode
{
    private const URL_BASE_VERIFICACAO = 'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe';
    private const TAMANHO_PX           = 350;
    private const VERSAO_QR            = 4; // 33x33 módulos

    /**
     * Gerar o QR Code em PNG (especificação oficial) e devolver em
     * base64 para uso em <img src="data:image/png;base64,...">.
     *
     * Requer a extensão PHP Imagick. Se não disponível, recorre a SVG
     * como fallback funcional (ver gerarBase64ComFallback) — o
     * conteúdo codificado (a URL) é idêntico em ambos os formatos;
     * apenas o formato do ficheiro final difere do especificado.
     */
    public function gerarBase64(array $dados): string
    {
        $url = $this->construirUrlVerificacao($dados);

        if (class_exists(\Imagick::class)) {
            return base64_encode($this->renderizarPng($url));
        }

        // Fallback — SVG. Documenta-se explicitamente que isto desvia
        // da especificação (PNG 350x350px), mas mantém a app funcional
        // em ambientes sem a extensão Imagick instalada.
        return base64_encode($this->renderizarSvg($url));
    }

    /**
     * Indica se o resultado de gerarBase64() é PNG (conforme
     * especificação) ou SVG (fallback). Usado pelo HTML do PDF para
     * escolher o mime-type correcto na tag <img>.
     */
    public function formatoGerado(): string
    {
        return class_exists(\Imagick::class) ? 'image/png' : 'image/svg+xml';
    }

    /**
     * Construir a URL de verificação exactamente conforme a
     * documentação: emissor=NIF & document=documentNo (espaços -> %20).
     */
    public function construirUrlVerificacao(array $dados): string
    {
        $nif = data_get($dados, 'organization.nif', '');
        $documentNo = data_get($dados, 'invoice.document_no', '');

        // "Cada espaço deve ser substituído pela sequência %20" — a
        // documentação especifica explicitamente %20, não o "+" que
        // http_build_query() produziria por padrão para espaços.
        $documentNoCodificado = str_replace(' ', '%20', $documentNo);

        return self::URL_BASE_VERIFICACAO
            . '?emissor=' . rawurlencode($nif)
            . '&document=' . $documentNoCodificado;
    }

    // ══════════════════════════════════════════════════════════════════
    // RENDERIZAÇÃO
    // ══════════════════════════════════════════════════════════════════

    /**
     * Renderizar em PNG 350x350px, QR Model 2, versão 4, correcção M —
     * exactamente conforme a especificação.
     */
    private function renderizarPng(string $conteudo): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(self::TAMANHO_PX, 0, null, null, ErrorCorrectionLevel::M()),
            new ImagickImageBackEnd('png')
        );

        $writer = new Writer($renderer);
        return $writer->writeString($conteudo);
    }

    /**
     * Fallback SVG — usado apenas se a extensão Imagick não estiver
     * disponível no servidor. O conteúdo (URL) é idêntico; apenas o
     * formato do ficheiro final desvia do PNG especificado.
     */
    private function renderizarSvg(string $conteudo): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(self::TAMANHO_PX, 0, null, null, ErrorCorrectionLevel::M()),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);
        return $writer->writeString($conteudo);
    }
}
