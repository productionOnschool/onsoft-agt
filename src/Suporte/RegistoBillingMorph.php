<?php

namespace Onsoft\Agt\Suporte;

/**
 * RegistoBillingMorph
 *
 * Registo central e extensível de todos os modelos de billing
 * que fazem morph com InvoiceItem via invoiceable_type / invoiceable_id.
 *
 * ══════════════════════════════════════════════════════════════════════
 * COMO FUNCIONA O MORPH
 * ══════════════════════════════════════════════════════════════════════
 *
 * A tabela invoice_items tem duas colunas polimórficas:
 *   - invoiceable_type  → FQCN do modelo (ex: "App\Models\Invoice\Billing\BillingPropina")
 *   - invoiceable_id    → ID do registo nesse modelo
 *
 * Cada modelo de billing tem:
 *   public function invoiceItems(): MorphMany { ... }
 *
 * Cada InvoiceItem tem:
 *   public function invoiceable(): MorphTo { ... }
 *
 * ══════════════════════════════════════════════════════════════════════
 * MODELOS ACTUAIS
 * ══════════════════════════════════════════════════════════════════════
 *
 * | Chave             | Modelo                        | Tabela                      |
 * |-------------------|-------------------------------|------------------------------|
 * | propina           | BillingPropina                | billing_propinas             |
 * | matricula         | BillingMatricula              | billing_matriculas           |
 * | confirmacao       | BillingConfirmacao            | billing_confirmacoes         |
 * | recurso           | BillingRecurso                | billing_recursos             |
 * | transporte        | BillingTransporte             | billing_transportes          |
 * | produto           | PedagogicalProduct            | pedagogical_products         |
 *
 * NOTA (corrigido nesta auditoria): a documentação listava também
 * "categoria_produto" → PedagogicalProductCategory, mas esse tipo
 * NUNCA foi incluído em inicializar() — uma categoria de produto não
 * é tipicamente algo que se factura directamente (factura-se o
 * PRODUTO, não a sua categoria), por isso a linha foi removida da
 * documentação em vez de adicionada ao código. Se este caso de uso
 * for genuinamente necessário, registar explicitamente via
 * RegistoBillingMorph::registar('categoria_produto', ...).
 *
 * ══════════════════════════════════════════════════════════════════════
 * COMO ADICIONAR UM NOVO TIPO DE BILLING (EXEMPLO: BillingSeguro)
 * ══════════════════════════════════════════════════════════════════════
 *
 * 1. Criar o modelo com morphMany (igual aos outros Billing):
 *
 *    class BillingSeguro extends Model {
 *        use BelongsToOrganization;
 *        protected $table = 'billing_seguros';
 *        public function invoiceItems(): MorphMany {
 *            return $this->morphMany(InvoiceItem::class, 'invoiceable');
 *        }
 *    }
 *
 * 2. Criar a migração da tabela billing_seguros.
 *
 * 3. Registar aqui — UMA LINHA:
 *
 *    RegistoBillingMorph::registar('seguro', \App\Models\Invoice\Billing\BillingSeguro::class);
 *
 * 4. Chamar no AppServiceProvider ou no seu ServiceProvider:
 *
 *    \Onsoft\Agt\Suporte\RegistoBillingMorph::registar(
 *        'seguro',
 *        \App\Models\Invoice\Billing\BillingSeguro::class
 *    );
 *
 * Pronto. O pacote adapta-se automaticamente — PDF, relatórios,
 * estatísticas e snapshots incluem o novo tipo sem mais alterações.
 *
 * ══════════════════════════════════════════════════════════════════════
 */
class RegistoBillingMorph
{
    /**
     * Registo central: chave → FQCN do modelo.
     */
    private static array $registo = [];

    /**
     * Inicializar com os modelos padrão do projecto.
     * Chamado automaticamente pelo OnsoftAgtServiceProvider.
     */
    public static function inicializar(): void
    {
        $modelos = [
            'propina'           => \App\Models\Invoice\Billing\BillingPropina::class,
            'matricula'         => \App\Models\Invoice\Billing\BillingMatricula::class,
            'confirmacao'       => \App\Models\Invoice\Billing\BillingConfirmacao::class,
            'recurso'           => \App\Models\Invoice\Billing\BillingRecurso::class,
            'transporte'        => \App\Models\Invoice\Billing\BillingTransporte::class,
            'produto'           => \App\Models\Invoice\Billing\PedagogicalProduct::class,
        ];

        foreach ($modelos as $chave => $classe) {
            if (class_exists($classe)) {
                self::$registo[$chave] = $classe;
            }
        }
    }

    /**
     * Registar um novo tipo de billing (extensão pelo projecto).
     *
     * @param string $chave   Identificador único (ex: 'seguro', 'multa')
     * @param string $classe  FQCN do modelo (ex: App\Models\Invoice\Billing\BillingSeguro::class)
     */
    public static function registar(string $chave, string $classe): void
    {
        if (!class_exists($classe)) {
            throw new \InvalidArgumentException(
                "RegistoBillingMorph: a classe [{$classe}] não existe. " .
                "Crie o modelo antes de o registar."
            );
        }

        self::$registo[$chave] = $classe;
    }

    /**
     * Obter todos os modelos registados.
     * @return array<string, string>
     */
    public static function todos(): array
    {
        return self::$registo;
    }

    /**
     * Obter o FQCN de um tipo de billing pela chave.
     */
    public static function obter(string $chave): ?string
    {
        return self::$registo[$chave] ?? null;
    }

    /**
     * Verificar se um tipo está registado.
     */
    public static function existe(string $chave): bool
    {
        return isset(self::$registo[$chave]);
    }

    /**
     * Obter a chave a partir do FQCN do modelo (para o snapshot).
     */
    public static function chaveParaClasse(string $fqcn): ?string
    {
        return array_search($fqcn, self::$registo, true) ?: null;
    }

    /**
     * Resolver o modelo source de um InvoiceItem.
     * Devolve o registo billing associado ou null.
     *
     * @param \App\Models\Invoice\InvoiceItem $item
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public static function resolverFonte(\App\Models\Invoice\InvoiceItem $item): ?\Illuminate\Database\Eloquent\Model
    {
        if (empty($item->invoiceable_type) || empty($item->invoiceable_id)) {
            return null;
        }

        // Verificar se o tipo está no registo
        $chave = self::chaveParaClasse($item->invoiceable_type);
        if ($chave === null && !class_exists($item->invoiceable_type)) {
            return null;
        }

        try {
            return $item->invoiceable_type::find($item->invoiceable_id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Listar tipos registados com informação básica (para documentação/debug).
     */
    public static function listar(): array
    {
        return array_map(function ($chave, $classe) {
            $tabela = null;
            try {
                $instancia = new $classe();
                $tabela = $instancia->getTable();
            } catch (\Throwable) {}

            return [
                'chave'   => $chave,
                'classe'  => $classe,
                'tabela'  => $tabela,
                'existe'  => class_exists($classe),
            ];
        }, array_keys(self::$registo), self::$registo);
    }
}
