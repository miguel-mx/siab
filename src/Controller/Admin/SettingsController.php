<?php

namespace App\Controller\Admin;

use App\Engine\CitationEngineClient;
use App\Engine\EngineLauncher;
use App\Entity\User;
use App\Enum\SettingKey;
use App\Health\ServiceHealthChecker;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Engine configuration.
 *
 * SIAB is the source of truth: values live in the `setting` table and travel to the
 * Python engine on each call, so a change here applies to the next analysis without
 * restarting uvicorn — which is what made the Ollama address wrong for a whole day
 * when it could only be set in citas-engine/.env.
 */
#[Route('/admin/configuracion')]
#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('', name: 'app_admin_settings', methods: ['GET'])]
    public function index(SettingsService $settings, EngineLauncher $launcher, ServiceHealthChecker $health): Response
    {
        return $this->render('admin/settings.html.twig', [
            'rows' => $this->rows($settings),
            // The process control sits above the settings: a failed start redirects
            // here, and its log tail is the only place the reason is written down.
            'engine' => [
                'configured' => $launcher->isConfigured(),
                'command' => $launcher->command(),
                'owns' => $launcher->ownsTheProcess(),
                'directory' => $launcher->workingDirectory(),
                'status' => $health->check()->get('engine'),
                'log' => $launcher->logTail(),
            ],
        ]);
    }

    #[Route('', name: 'app_admin_settings_save', methods: ['POST'])]
    public function save(Request $request, SettingsService $settings): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $this->redirectToRoute('app_admin_settings');
        }

        $actor = $this->getUser();

        // "Restablecer" is a named submit on the same form (a nested <form> would be
        // invalid HTML), so it is handled before the save pass.
        if ($reset = $request->request->get('reset')) {
            return $this->reset((string) $reset, $settings, $actor instanceof User ? $actor : null);
        }

        $errors = [];
        $changed = 0;

        foreach (SettingKey::cases() as $key) {
            $submitted = $request->request->get($key->value);

            // A secret left blank means "keep what is stored" — the form never shows
            // the current value, so an empty box cannot be read as "clear it".
            if ($submitted === null || ($key->isSecret() && trim((string) $submitted) === '')) {
                continue;
            }

            $value = trim((string) $submitted);

            if ($error = $key->validate($value)) {
                $errors[] = sprintf('%s: %s', $key->label(), $error);
                continue;
            }

            if ($value !== $settings->get($key) || ($value === '' && $settings->isOverridden($key))) {
                $settings->set($key, $value, $actor instanceof User ? $actor : null);
                ++$changed;
            }
        }

        if ($errors !== []) {
            // Nothing is written when any field is invalid: a half-applied engine
            // configuration is worse than none.
            $this->addFlash('error', implode(' ', $errors));

            return $this->redirectToRoute('app_admin_settings');
        }

        if ($changed > 0) {
            $settings->flush();
            $this->addFlash('success', sprintf(
                '%d %s. Se aplican al siguiente análisis; no hace falta reiniciar el motor.',
                $changed,
                $changed === 1 ? 'ajuste guardado' : 'ajustes guardados',
            ));
        } else {
            $this->addFlash('success', 'Sin cambios que guardar.');
        }

        return $this->redirectToRoute('app_admin_settings');
    }

    /**
     * Ask the source whether the stored key works.
     *
     * The alternative was launching an analysis and reading the warnings twenty
     * minutes later — which is how a rejected Web of Science key went unnoticed.
     */
    #[Route('/probar/{key}', name: 'app_admin_settings_test_key', methods: ['POST'])]
    public function testKey(
        string $key,
        Request $request,
        SettingsService $settings,
        CitationEngineClient $engine,
    ): Response {
        $redirect = $this->redirectToRoute('app_admin_settings');

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $redirect;
        }

        $setting = SettingKey::tryFrom($key);
        $source = $setting?->apiKeySource();

        if ($setting === null || $source === null) {
            $this->addFlash('error', 'Ese ajuste no es una clave de API que se pueda probar.');

            return $redirect;
        }

        $result = $engine->checkSourceKey($source, $settings->get($setting));
        $this->addFlash($result['ok'] ? 'success' : 'error', sprintf(
            '%s: %s',
            $setting->label(),
            $result['message'],
        ));

        return $redirect;
    }

    /** Clears one override so the setting returns to its .env default. */
    private function reset(string $key, SettingsService $settings, ?User $actor): Response
    {
        $setting = SettingKey::tryFrom($key);

        if ($setting === null) {
            $this->addFlash('error', 'Ajuste desconocido.');
        } else {
            $settings->set($setting, '', $actor);
            $settings->flush();
            $this->addFlash('success', sprintf('«%s» volvió a su valor por defecto.', $setting->label()));
        }

        return $this->redirectToRoute('app_admin_settings');
    }

    /**
     * @return list<array{key: SettingKey, value: string, overridden: bool, default: string, updated_at: ?\DateTimeImmutable, updated_by: ?User}>
     */
    private function rows(SettingsService $settings): array
    {
        return array_map(static fn (SettingKey $key) => [
            'key' => $key,
            // Secrets are never handed to the template — only whether one is set.
            'value' => $key->isSecret() ? '' : $settings->get($key),
            // Never the key itself: length and last four characters only.
            'preview' => $settings->preview($key),
            'overridden' => $settings->isOverridden($key),
            'default' => $key->isSecret() ? '' : $settings->default($key),
            'updated_at' => $settings->updatedAt($key),
            'updated_by' => $settings->updatedBy($key),
        ], SettingKey::cases());
    }
}
