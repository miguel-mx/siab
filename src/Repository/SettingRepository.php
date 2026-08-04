<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /**
     * Every stored setting, indexed by name. One query — SettingsService reads the
     * whole set on first use and answers from it.
     *
     * @return array<string, Setting>
     */
    public function findAllIndexed(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $setting) {
            $indexed[$setting->getName()] = $setting;
        }

        return $indexed;
    }
}
