<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use App\Services\DataExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Canal de direitos do titular (LGPD) — Reachyn.
 * Página "Privacidade & Meus Dados" autenticada: exportar dados (portabilidade, art. 18 V)
 * e excluir a conta (eliminação, art. 18 VI), além do contato do Encarregado.
 *
 * A "conta" é a Organization do usuário logado. Exclusão exige tripla confirmação
 * (senha + slug + ciência), espelhando o padrão do Nexusyn.
 */
class AccountPrivacyController extends Controller
{
    /** Página de privacidade do usuário. */
    public function index(Request $request, AccountDeletionService $svc)
    {
        $org = $this->org($request);
        abort_unless($org, 404);

        return view('account.privacy', [
            'org' => $org,
            'pending' => $svc->isPending($org),
            'scheduledFor' => $org->deletion_scheduled_for,
        ]);
    }

    /** Export de portabilidade (download JSON). */
    public function export(Request $request, DataExportService $svc)
    {
        $org = $this->org($request);
        abort_unless($org, 404);

        $data = $svc->export($org);
        $filename = 'reachyn-export-'.$org->slug.'-'.now()->format('Y-m-d').'.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Solicita a exclusão (Ato 1): senha + slug + ciência. */
    public function requestDeletion(Request $request, AccountDeletionService $svc)
    {
        $org = $this->org($request);
        abort_unless($org, 404);

        $request->validate([
            'password' => ['required', 'string'],
            'slug_confirm' => ['required', 'string'],
            'acknowledge' => ['accepted'],
        ]);

        $user = Auth::user();
        if (! Hash::check($request->input('password'), (string) $user->password)) {
            return back()->withErrors(['password' => 'Senha incorreta.']);
        }
        if (trim((string) $request->input('slug_confirm')) !== (string) $org->slug) {
            return back()->withErrors(['slug_confirm' => 'O identificador digitado não confere.']);
        }

        $svc->request($org, $user, $request->ip());

        return redirect()->route('account.privacy')
            ->with('status', 'Exclusão agendada. Enviamos um e-mail com o link para cancelar, caso mude de ideia.');
    }

    /** Cancela a exclusão pendente (painel). */
    public function cancelDeletion(Request $request, AccountDeletionService $svc)
    {
        $org = $this->org($request);
        abort_unless($org, 404);

        $svc->cancel($org);

        return redirect()->route('account.privacy')->with('status', 'Exclusão cancelada.');
    }

    /** Cancela via link do e-mail (público — o token é a credencial). */
    public function cancelByToken(string $token, AccountDeletionService $svc)
    {
        $ok = $svc->cancelByToken($token);

        return response()->view('account.deletion-result', ['ok' => $ok], $ok ? 200 : 404);
    }

    /** Organization do usuário logado. */
    protected function org(Request $request)
    {
        $user = Auth::user();

        return $user ? $user->organization : null;
    }
}
