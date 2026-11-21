<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Integracao;
use App\Servicos\Backup\GoogleDrive;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Configurações › Backup: backup diário no servidor e a cópia criptografada no Google Drive. O dono cria o app no
 * Google Cloud e informa aqui o ID e a chave do cliente (criptografados) e a senha do backup; depois conecta a conta.
 */
class BackupController extends Controller
{
    private function integracao(): Integracao
    {
        return GoogleDrive::integracao() ?? new Integracao(['tipo' => 'google_drive', 'ambiente' => 'producao', 'ativo' => false]);
    }

    public function index(): Response
    {
        $i = $this->integracao();
        $locais = collect(Storage::disk('local')->files('backups'))->filter(fn ($f) => str_ends_with($f, '.sql.gz'))
            ->sortByDesc(fn ($f) => Storage::disk('local')->lastModified($f))->take(6)
            ->map(fn ($f) => ['nome' => basename($f), 'kb' => (int) round(Storage::disk('local')->size($f) / 1024), 'em' => date('d/m/Y H:i', Storage::disk('local')->lastModified($f))])->values();

        return Inertia::render('Configuracoes/Backup', [
            'drive' => [
                'tem_client' => (bool) ($i->credencial('client_id') && $i->credencial('client_secret')),
                'client_id' => $i->credencial('client_id'),
                'tem_senha' => (bool) $i->credencial('senha'),
                'dias' => (int) ($i->credencial('dias') ?: 30),
                'conectado' => (bool) ($i->ativo && $i->credencial('refresh_token')),
                'conta' => $i->credencial('conta'),
                'pasta' => GoogleDrive::PASTA,
                'ultimo_ok' => $i->testado_em?->format('d/m/Y H:i'),
                'ultimo_envio' => ($i->credenciais ?? [])['ultimo_envio'] ?? null,
                'ultimo_erro' => $i->ultimo_erro,
                'retorno' => GoogleDrive::urlRetorno(),
            ],
            'locais' => $locais,
            'dias_locais' => (int) config('erp.backup_dias'),
        ]);
    }

    public function salvar(Request $request): RedirectResponse
    {
        $i = $this->integracao();
        $d = $request->validate([
            'client_id' => ['required', 'string', 'max:200', 'regex:/\.apps\.googleusercontent\.com$/'],
            'client_secret' => [$i->credencial('client_secret') ? 'nullable' : 'required', 'string', 'max:200'],
            'senha' => [$i->credencial('senha') ? 'nullable' : 'required', 'string', 'min:12', 'max:200', 'confirmed'],
            'dias' => ['required', 'integer', 'min:7', 'max:365'],
        ], [
            'client_id.regex' => 'O ID do cliente termina em .apps.googleusercontent.com.',
            'senha.min' => 'Use pelo menos 12 caracteres (uma frase serve).',
            'senha.confirmed' => 'As duas senhas não conferem.',
        ]);
        $cred = $i->credenciais ?? [];
        if (($cred['client_id'] ?? null) !== $d['client_id']) {
            unset($cred['refresh_token']);   // outro app do Google: precisa conectar de novo
            $i->ativo = false;
        }
        $cred['client_id'] = trim($d['client_id']);
        $cred['dias'] = (int) $d['dias'];
        foreach (['client_secret', 'senha'] as $k) {
            if (filled($d[$k] ?? null)) {
                $cred[$k] = $k === 'senha' ? $d[$k] : trim($d[$k]);
            }
        }
        $i->empresa_id ??= Empresa::atual()->id;
        $i->credenciais = $cred;
        $i->save();
        Atividades::registrar('backup_drive_configurado', filled($d['senha'] ?? null) ? 'Google Drive: dados e senha do backup salvos' : 'Google Drive: dados salvos');

        return back()->with('sucesso', 'Dados salvos.'.($i->ativo ? '' : ' Agora conecte a conta do Google.'));
    }

    /** vai para a tela de autorização do Google */
    public function conectar(Request $request): HttpResponse
    {
        $i = $this->integracao();
        if (! $i->exists || ! $i->credencial('client_id') || ! $i->credencial('client_secret') || ! $i->credencial('senha')) {
            throw ValidationException::withMessages(['drive' => 'Salve o ID e a chave do cliente e a senha do backup antes de conectar.']);
        }
        $estado = Str::random(40);
        $request->session()->put('backup_google_estado', $estado);

        return Inertia::location((new GoogleDrive($i))->urlConexao($estado));
    }

    /** volta do Google com o código (ou com o erro, se a pessoa negou) */
    public function retorno(Request $request): RedirectResponse
    {
        $estado = $request->session()->pull('backup_google_estado');
        if (! $estado || ! hash_equals($estado, (string) $request->query('state'))) {
            return redirect('/configuracoes/backup')->with('erro', 'A volta do Google não confere com o pedido. Tente conectar de novo.');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect('/configuracoes/backup')->with('erro', 'O Google não autorizou ('.($request->query('error') ?: 'sem código').').');
        }
        $i = $this->integracao();
        try {
            (new GoogleDrive($i))->conectar((string) $request->query('code'));
        } catch (\Throwable $e) {
            return redirect('/configuracoes/backup')->with('erro', $e->getMessage());
        }
        Atividades::registrar('backup_drive_conectado', 'Google Drive conectado'.($i->credencial('conta') ? ': '.$i->credencial('conta') : ''));

        return redirect('/configuracoes/backup')->with('sucesso', 'Google Drive conectado. Use "Fazer backup agora" para conferir.');
    }

    /** backup completo agora (servidor + Drive), para conferir a conexão */
    public function agora(): RedirectResponse
    {
        @set_time_limit(600);
        $codigo = Artisan::call('backup:banco');
        $i = $this->integracao();
        Atividades::registrar('backup_manual', $codigo === 0 ? 'backup feito pela tela' : 'backup pela tela com falha');

        return $codigo === 0
            ? back()->with('sucesso', $i->ativo ? 'Backup feito e enviado ao Google Drive.' : 'Backup feito no servidor.')
            : back()->with('erro', 'O backup falhou: '.($i->ultimo_erro ?: trim(Artisan::output())));
    }

    public function desconectar(): RedirectResponse
    {
        $i = $this->integracao();
        if ($i->exists) {
            $cred = $i->credenciais ?? [];
            unset($cred['refresh_token'], $cred['conta']);
            $i->forceFill(['credenciais' => $cred, 'ativo' => false])->save();
            Atividades::registrar('backup_drive_desconectado', 'Google Drive desconectado');
        }

        return back()->with('sucesso', 'Google Drive desconectado. Os backups que já estão lá continuam no seu Drive.');
    }
}
