<?php

namespace Nails\Cdn\Driver\DigitalOCean\Settings;

use Nails\Common\Helper\Form;
use Nails\Common\Interfaces;
use Nails\Common\Service\FormValidation;
use Nails\Components\Setting;
use Nails\Factory;

/**
 * Class DigitalOcean
 *
 * @package Nails\Cdn\Driver\DigitalOCean\Settings
 */
class DigitalOcean implements Interfaces\Component\Settings
{
    const KEY_ACCESS_KEY         = 'access_key';
    const KEY_ACCESS_SECRET      = 'access_secret';
    const KEY_SPACES             = 'spaces';
    const KEY_URL_SERVE          = 'uri_serve';
    const KEY_URL_SERVE_SECURE   = 'uri_serve_secure';
    const KEY_URL_PROCESS        = 'uri_process';
    const KEY_URL_PROCESS_SECURE = 'uri_process_secure';

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'CDN: Digital Ocean Spaces';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        /** @var Setting $oAccessKey */
        $oAccessKey = Factory::factory('ComponentSetting');
        $oAccessKey
            ->setKey(static::KEY_ACCESS_KEY)
            ->setLabel('Access Key')
            ->setEncrypted(true)
            ->setFieldset('Credentials')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oAccessSecret */
        $oAccessSecret = Factory::factory('ComponentSetting');
        $oAccessSecret
            ->setKey(static::KEY_ACCESS_SECRET)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Access Secret')
            ->setEncrypted(true)
            ->setFieldset('Credentials')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oBuckets */
        $oBuckets = Factory::factory('ComponentSetting');
        $oBuckets
            ->setKey(static::KEY_SPACES)
            ->setType(Form::FIELD_TEXTAREA)
            ->setLabel('Spaces')
            ->setFieldset('Spaces')
            ->setInfo('Spaces should be specified as a JSON object with the environment as the key, and the data center and space as the value. e.g. <code>{"PRODUCTION":"datacenter:my-space"}</code>')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oUrlServe */
        $oUrlServe = Factory::factory('ComponentSetting');
        $oUrlServe
            ->setKey(static::KEY_URL_SERVE)
            ->setLabel('Serving URL')
            ->setFieldset('URLs')
            ->setDefault('https://{{space}}.{{data_center}}.digitaloceanspaces.com')
            ->setInfo('Value will be passed into <code>siteUrl()</code>')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oUrlServeSecure */
        $oUrlServeSecure = Factory::factory('ComponentSetting');
        $oUrlServeSecure
            ->setKey(static::KEY_URL_SERVE_SECURE)
            ->setLabel('Serving URL (Secure)')
            ->setFieldset('URLs')
            ->setDefault('https://{{space}}.{{data_center}}.digitaloceanspaces.com')
            ->setInfo('Value will be passed into <code>siteUrl()</code>')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oUrlProcess */
        $oUrlProcess = Factory::factory('ComponentSetting');
        $oUrlProcess
            ->setKey(static::KEY_URL_PROCESS)
            ->setLabel('Processing URL')
            ->setFieldset('URLs')
            ->setDefault('cdn')
            ->setInfo('Value will be passed into <code>siteUrl()</code>')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oUrlProcessSecure */
        $oUrlProcessSecure = Factory::factory('ComponentSetting');
        $oUrlProcessSecure
            ->setKey(static::KEY_URL_PROCESS_SECURE)
            ->setLabel('Processing URL (Secure)')
            ->setFieldset('URLs')
            ->setDefault('cdn')
            ->setInfo('Value will be passed into <code>siteUrl()</code>')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        return [
            $oAccessKey,
            $oAccessSecret,
            $oBuckets,
            $oUrlServe,
            $oUrlServeSecure,
            $oUrlProcess,
            $oUrlProcessSecure,
        ];
    }
}
