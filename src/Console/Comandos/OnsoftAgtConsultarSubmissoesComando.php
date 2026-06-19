<?php

namespace Onsoft\Agt\Console\Comandos;

use App\Models\Agt\AgtInvoiceSubmission;
use Illuminate\Console\Command;
use Onsoft\Agt\Servicos\ServicoContextoOrganizacao;

/**
 * OnsoftAgtConsultarSubmissoesComando
 *
 * ══════════════════════════════════════════════════════════════════════
 * PORQUÊ ESTE COMANDO EXISTE
 * ══════════════════════════════════════════════════════════════════════
 * A Ronda 7 da auditoria de conformidade encontrou que
 * ServicoSubmissao::consultarEstado() — o método responsável por
 * perguntar à AGT se uma fatura submetida foi aceite ou rejeitada —
 * nunca era chamado de nenhum lugar no pacote. Não havia endpoint
 * HTTP, comando agendado, nem job que o invocasse.
 *
 * Consequência: faturas submetidas ficavam para sempre com
 * agt_status='pending', mesmo que a AGT já tivesse respondido há
 * muito tempo. O PDF mostrava indefinidamente "aguarda resposta da
 * AGT", e os relatórios de estado AGT nunca reflectiam aceitação ou
 * rejeição reais.
 *
 * Este comando fecha esse ciclo: percorre todas as submissões em
 * estado 'pending' ou 'submitted' (por organização, respeitando o
 * multi-tenant), e chama consultarEstado() para cada uma — que por
 * sua vez (após a correcção da mesma ronda) propaga o resultado real
 * (accepted/rejected) para a fatura.
 *
 * ══════════════════════════════════════════════════════════════════════
 * AGENDAMENTO RECOMENDADO
 * ══════════════════════════════════════════════════════════════════════
 * Registado automaticamente no scheduler do pacote a correr de 5 em
 * 5 minutos — ver OnsoftAgtServiceProvider::boot(). Pode também ser
 * corrido manualmente em qualquer momento.
 */
class OnsoftAgtConsultarSubmissoesComando extends Command
{
    protected $signature = 'onsoft-agt:consultar-submissoes
                            {--organizacaoId= : Limitar a uma organização}
                            {--limite=50 : Número máximo de submissões a consultar por execução}';

    protected $description = 'Consultar a AGT pelo estado real de submissões pendentes e propagar para as faturas';

    public function handle(): int
    {
        $limite = (int) $this->option('limite');

        $query = AgtInvoiceSubmission::withoutGlobalScopes()
            ->whereIn('status', ['pending', 'submitted'])
            ->orderBy('submitted_at')
            ->limit($limite);

        if ($orgId = $this->option('organizacaoId')) {
            $query->where('organizationId', (int) $orgId);
        }

        $submissoes = $query->get();

        if ($submissoes->isEmpty()) {
            $this->info('Sem submissões pendentes para consultar.');
            return self::SUCCESS;
        }

        $this->info("🔍 Consultando {$submissoes->count()} submissão(ões) pendente(s)...");

        $aceites    = 0;
        $rejeitadas = 0;
        $aindaPendentes = 0;
        $erros      = 0;

        // Agrupar por organização — evita reconstruir o contexto AGT
        // (e desencriptar chaves) repetidamente para a mesma organização.
        foreach ($submissoes->groupBy('organizationId') as $orgId => $grupo) {
            try {
                $ctx     = new ServicoContextoOrganizacao((int) $orgId);
                $servico = $ctx->servicoSubmissao();

                foreach ($grupo as $submissao) {
                    $actualizada = $servico->consultarEstado($submissao);

                    match ($actualizada->status) {
                        'accepted' => $aceites++,
                        'rejected' => $rejeitadas++,
                        default    => $aindaPendentes++,
                    };
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ Organização [{$orgId}]: " . $e->getMessage());
                $erros++;
            }
        }

        $this->newLine();
        $this->info("✅ Aceites: {$aceites}  |  ❌ Rejeitadas: {$rejeitadas}  |  🕐 Ainda pendentes: {$aindaPendentes}");
        if ($erros > 0) {
            $this->warn("⚠️ Organizações com erro de contexto: {$erros}");
        }

        return self::SUCCESS;
    }
}
