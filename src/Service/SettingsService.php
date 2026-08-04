<?php

namespace App\Service;

use App\Entity\Setting;
use App\Entity\User;
use App\Enum\SettingKey;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Effective engine configuration: the administrator's stored value when there is
 * one, otherwise the deployment default from .env.
 *
 * Rows are loaded once per request and cached, because the engine client asks for
 * several of these on every call.
 */
final class SettingsService
{
    /** @var array<string, Setting>|null */
    private ?array $stored = null;

    /** @var array<string, string> */
    private readonly array $defaults;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $secrets,
        #[Autowire('%env(CITATION_ENGINE_URL)%')] string $engineUrl,
        #[Autowire('%env(default::OLLAMA_BASE)%')] ?string $ollamaBase,
        #[Autowire('%env(default::OLLAMA_MODEL)%')] ?string $ollamaModel,
    ) {
        $this->defaults = [
            SettingKey::ENGINE_URL->value => $engineUrl,
            // These mirror citas-engine/app/config.py so an unset SIAB value and the
            // engine's own fallback agree on what is in effect.
            SettingKey::OLLAMA_BASE->value => $ollamaBase ?: 'http://localhost:11434',
            SettingKey::OLLAMA_MODEL->value => $ollamaModel ?: 'gemma4:e4b',
            SettingKey::MAX_WORKS->value => '500',
            SettingKey::MAX_CITING_PER_WORK->value => '1000',
            SettingKey::COUNT_TOLERANCE->value => '0.10',
            SettingKey::SCOPUS_API_KEY->value => '',
            SettingKey::WOS_API_KEY->value => '',
        ];
    }

    /**
     * The value in effect, decrypted if it is a secret. Never null for non-secrets:
     * an unset or cleared setting falls back to its default.
     */
    public function get(SettingKey $key): string
    {
        $stored = $this->stored()[$key->value] ?? null;
        $raw = $stored?->getValue();

        if ($raw === null || $raw === '') {
            return $this->defaults[$key->value];
        }

        if (!$key->isSecret()) {
            return $raw;
        }

        // Undecryptable (APP_SECRET rotated) is treated as "not configured" rather
        // than as an error, so one stale row cannot break every page that reads it.
        return $this->secrets->decrypt($raw) ?? $this->defaults[$key->value];
    }

    public function getInt(SettingKey $key): int
    {
        return (int) $this->get($key);
    }

    public function getFloat(SettingKey $key): float
    {
        return (float) $this->get($key);
    }

    /** Whether an administrator has set this away from its deployment default. */
    /**
     * A stored secret described without revealing it: how long it is and its last
     * four characters. Enough for an administrator to tell which key is saved —
     * and to see that one *is* saved — without the value ever reaching a template.
     */
    public function preview(SettingKey $key): ?string
    {
        if (!$key->isSecret()) {
            return null;
        }

        $value = trim($this->get($key));

        if ($value === '') {
            return null;
        }

        return sprintf('%d caracteres · termina en …%s', mb_strlen($value), mb_substr($value, -4));
    }

    public function isOverridden(SettingKey $key): bool
    {
        $stored = $this->stored()[$key->value] ?? null;

        return $stored !== null && $stored->getValue() !== null && $stored->getValue() !== '';
    }

    public function default(SettingKey $key): string
    {
        return $this->defaults[$key->value];
    }

    public function updatedAt(SettingKey $key): ?\DateTimeImmutable
    {
        return ($this->stored()[$key->value] ?? null)?->getUpdatedAt();
    }

    public function updatedBy(SettingKey $key): ?User
    {
        return ($this->stored()[$key->value] ?? null)?->getUpdatedBy();
    }

    /**
     * Stores a value, encrypting it first when the key is a secret. An empty string
     * clears the override and returns the setting to its default.
     */
    public function set(SettingKey $key, string $value, ?User $actor = null): void
    {
        $value = trim($value);

        $setting = $this->stored()[$key->value] ?? null;
        if ($setting === null) {
            $setting = new Setting($key->value);
            $this->em->persist($setting);
            $this->stored[$key->value] = $setting;
        }

        $setting
            ->setValue($value === '' ? null : ($key->isSecret() ? $this->secrets->encrypt($value) : $value))
            ->setUpdatedBy($actor);
    }

    public function flush(): void
    {
        $this->em->flush();
        $this->stored = null; // re-read on next access so callers see what was written
    }

    /** @return array<string, Setting> */
    private function stored(): array
    {
        return $this->stored ??= $this->settings->findAllIndexed();
    }
}
