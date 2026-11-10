<?php

namespace App\Providers;

use App\Auth\UsuarioProvider;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmpresaAtual::class);
    }

    public function boot(): void
    {
        // avisa no computador quando o código carrega relação sem querer ou usa campo inexistente
        Model::shouldBeStrict(! $this->app->isProduction());

        // IP real do visitante: só aceito quando a requisição vem do Cloudflare
        TrustProxies::at(config('erp.proxies_confiaveis'));

        // usuário do login/sessão é buscado sem o filtro de empresa (ver UsuarioProvider)
        Auth::provider('usuarios', fn ($app, array $config) => new UsuarioProvider($app['hash'], $config['model']));

        // assim que se sabe quem é o usuário, a empresa dele vira a empresa atual
        $definirEmpresa = fn ($evento) => $this->app->make(EmpresaAtual::class)->definir($evento->user->empresa_id);
        Event::listen(Authenticated::class, $definirEmpresa);
        Event::listen(Login::class, $definirEmpresa);

        // login: até 5 tentativas por minuto para o mesmo login e IP
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('login')).'|'.$request->ip())
            ->response(fn () => back()->withErrors(['login' => 'Muitas tentativas seguidas. Espere um minuto e tente de novo.'])));

        // permissões do catálogo valem também em Gate/@can/authorize()
        Gate::before(function ($usuario, string $permissao) {
            if ($usuario instanceof Usuario && $usuario->pode($permissao)) {
                return true;
            }

            return null;
        });
    }
}
