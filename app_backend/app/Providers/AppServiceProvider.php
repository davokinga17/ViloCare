<?php

namespace App\Providers;

use App\Services\Ai\AiProviderInterface;
use App\Services\Ai\GeminiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProviderInterface::class, function () {
            $provider = config('services.ai.provider', 'gemini');

            return match ($provider) {
                'gemini' => new GeminiProvider(),
                default => new GeminiProvider(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureSslCaBundle();
    }

    /**
     * Configure global HTTP client SSL verification using the bundled CA certificate.
     * Prevents Windows cURL error 60 without requiring manual php.ini edits.
     */
    protected function configureSslCaBundle(): void
    {
        $sslVerify = config('sync.ssl_verify', true);

        if (!$sslVerify) {
            Http::globalOptions(['verify' => false]);
            return;
        }

        $caBundle = config('sync.ca_bundle', base_path('certs/cacert.pem'));

        if (!empty($caBundle) && file_exists($caBundle)) {
            Http::globalOptions(['verify' => $caBundle]);
        }
    }
}

