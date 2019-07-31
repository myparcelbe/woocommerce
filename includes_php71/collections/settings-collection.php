<?php

namespace WPO\WC\MyParcelBE\Collections;

use http\Exception\BadMethodCallException;
use MyParcelNL\Sdk\src\Support\Collection;
use WPO\WC\MyParcelBE\Entity\Setting;

defined('ABSPATH') or exit;

if (! class_exists('\\WPO\\WC\\MyParcelBE\\Collections\\SettingsCollection')) :
    /**
     * @mixin Setting
     */
    class SettingsCollection extends Collection
    {
        /**
         * @param array $rawSettings
         * @param string $type
         * @param int|null $carrierId
         */
        public function setSettingsByType(array $rawSettings, string $type, int $carrierId = null)
        {
            foreach ($rawSettings as $name => $value) {
                $setting = new Setting($name, $value, $type, $carrierId);
                $this->push($setting);
            }
        }

        public function isEnabled(string $name): bool
        {
            /** @var Setting|null $setting */
            $setting = $this->where('name', $name)->first();
            if (! $setting) {
                return false;
            }

            return $setting->value;
        }

        /**
         * @param string $name
         * @param string $value
         *
         * @return SettingsCollection
         */
        public function like(string $name, string $value): self
        {
            return $this->filter(function(Setting $item) use ($name, $value) {
                return false !== strpos($item->name, $value);
            });
        }

        public function getByName(string $name)
        {
            /** @var Setting $setting */
            $setting = $this->where('name', $name)->first();

            return $setting->value ?? null;
        }
    }
endif; // Class exists check