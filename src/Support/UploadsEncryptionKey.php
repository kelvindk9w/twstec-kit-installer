<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

/**
 * A chave dos uploads CONFIDENCIAIS (UPLOADS_ENCRYPTION_KEY, do
 * twstec/kit-uploads), gerada no `.env` quando o módulo de uploads entra —
 * como a APP_KEY e o pepper das chaves de API.
 *
 * O formato é o que o `php artisan uploads:encryption-key` gera (`base64:` +
 * 32 bytes aleatórios): o instalador não depende do pacote de uploads para
 * isso. Chave já definida nunca é trocada (os arquivos cifrados com ela
 * deixariam de abrir). O valor nunca é impresso — o resumo diz só "gerada" ou
 * "já definida".
 */
final class UploadsEncryptionKey
{
    public const VARIABLE = 'UPLOADS_ENCRYPTION_KEY';

    /**
     * Gera a chave se ela falta. Devolve true quando gerou.
     */
    public static function ensure(EnvironmentFile $env): bool
    {
        if ($env->filled(self::VARIABLE)) {
            return false;
        }

        $env->set(self::VARIABLE, 'base64:'.base64_encode(random_bytes(32)));

        return true;
    }
}
