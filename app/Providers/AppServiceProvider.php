<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerBrevoMailTransport();
    }

    /**
     * Laravel ships transports for Resend/Postmark/SES but not Brevo, so the
     * Symfony bridge is wired in by hand. `brevo+api` posts each message to
     * /v3/smtp/email — the transactional endpoint. Deliberately not
     * `brevo+smtp` (that is the same relay we moved off) and not the
     * campaign API, which sends to saved contact lists and cannot carry a
     * per-recipient code.
     */
    private function registerBrevoMailTransport(): void
    {
        Mail::extend('brevo', function (array $config) {
            $key = $config['key'] ?? config('services.brevo.key');

            // The DSN carries the API key as its user component; 'default'
            // as host leaves the bridge on api.brevo.com.
            return (new BrevoTransportFactory)->create(
                new Dsn('brevo+api', 'default', $key),
            );
        });
    }
}
