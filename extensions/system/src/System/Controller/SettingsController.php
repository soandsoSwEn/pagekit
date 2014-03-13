<?php

namespace Pagekit\System\Controller;

use Pagekit\Component\Config\Config;
use Pagekit\Framework\Controller\Controller;

/**
 * @Access("system: access settings", admin=true)
 */
class SettingsController extends Controller
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var string
     */
    protected $configFile;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->config = new Config;
        $this->config->load($this->configFile = $this('config.file'));
    }

    /**
     * @Request({"tab": "int"})
     * @View("system/admin/settings/settings.razr.php")
     */
    public function indexAction($tab = 0)
    {
        $supported = $this('cache')->supports();

        $caches = array(
            'auto' => array('name' => '', 'supported' => true),
            'apc' => array('name' => 'APC', 'supported' => in_array('apc', $supported)),
            'file' => array('name' => 'File', 'supported' => in_array('file', $supported))
        );

        $caches['auto']['name'] = 'Auto ('.$caches[end($supported)]['name'].')';

        $countries = $this('countries');
        $languages = $this('languages');

        $codes = array('en_US');

        foreach ($this('file')->find()->directories()->depth(0)->in('extension://system/languages')->name('/^[a-z]{2}(_[A-Z]{2})?$/') as $dir) {
            $codes[] = $dir->getFileName();
        }

        $locales = array();

        foreach ($codes as $code) {
            list($lang, $country) = explode('_', $code);
            $locales[$code] = $languages->isoToName($lang).' - '.$countries->isoToName($country);
        }

        $timezones = $this->getTimezones();

        $ssl = extension_loaded('openssl');

        $sqlite = class_exists('SQLite3') || (class_exists('PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true));

        return array('head.title' => __('Settings'), 'option' => $this('option'), 'config' => $this->config, 'cache' => $this->config->get('cache.storage', 'auto'), 'caches' => $caches, 'locales' => $locales, 'timezones' => $timezones, 'tab' => $tab, 'ssl' => $ssl, 'sqlite' => $sqlite);
    }

    /**
     * @Request({"config": "array", "option": "array", "tab": "int"})
     * @Token
     */
    public function saveAction($data, $option, $tab = 0)
    {
        // TODO: validate
        $data['app.debug'] = @$data['app.debug'] ?: '0';
        $data['profiler.enabled'] = @$data['profiler.enabled'] ?: '0';
        $data['app.nocache'] = @$data['app.nocache'] ?: '0';
        $data['cache.storage'] = @$data['cache.storage'] ?: 'auto';
        $option['system:app.site_title'] = @$option['system:app.site_title'] ?: '';
        $option['system:maintenance.enabled'] = @$option['system:maintenance.enabled'] ?: '0';
        $option['system:mail.enabled'] = @$option['system:mail.enabled'] ?: '0';

        foreach ($data as $key => $value) {
            $this->config->set($key, $value);
        }

        file_put_contents($this->configFile, $this->config->dump());

        foreach ($option as $key => $value) {
            $this('option')->set($key, $value, true);
        }

        if ($data['cache.storage'] != $this('config')->get('cache.storage') || $data['app.debug'] != $this('config')->get('app.debug')) {
            $this('system')->clearCache();
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->configFile);
        }

        $this('message')->success(__('Settings saved.'));

        return $this->redirect('@system/settings/index', compact('tab'));
    }

    /**
     * @Request({"option": "array"})
     * @Token
     */
    public function testSmtpConnectionAction($option = array())
    {
        $option = array_merge(array(
            'system:mail.port' => '',
            'system:mail.host' => '',
            'system:mail.username' => '',
            'system:mail.password' => '',
            'system:mail.encryption' => ''
        ), $option);

        $response = array('success' => true, 'message' => __('Connection established!'));

        try {

            $this('mailer')->testSmtpConnection($option['system:mail.host'], $option['system:mail.port'], $option['system:mail.username'], $option['system:mail.password'], $option['system:mail.encryption']);

        } catch(\Exception $e) {
            $response = array('success' => false, 'message' => sprintf(__('Connection not established! (%s)'), $e->getMessage()));
        }

        return $this('response')->json($response);
    }

    /**
     * Note: If the mailer is accessed prior to this controller action, this will possibly test the wrong mailer
     *
     * @Request({"option": "array"})
     * @Token
     */
    public function testSendEmailAction($option = array())
    {
        $filter = array_fill_keys(array(
            'system:mail.driver',
            'system:mail.port',
            'system:mail.host',
            'system:mail.username',
            'system:mail.password',
            'system:mail.encryption',
            'system:mail.from.address',
            'system:mail.from.name'
        ), null);

        $option = array_intersect_key(array_merge($filter, $option), $filter);

        try {

            foreach($option as $key => $value) {
                $this('option')->set($key, $value);
            }

            $response['success'] = filter_var($option['system:mail.from.address'], FILTER_VALIDATE_EMAIL) && $this('mailer')->create()
                ->to($option['system:mail.from.address'])
                ->from($option['system:mail.from.address'])
                ->subject('Test email!')
                ->body('Testemail')
                ->send();

            $response['message'] = $response['success'] ? __('Mail successfully sent!') : __('Mail delivery failed!');

        } catch (\Exception $e) {
            $response = array('success' => false, 'message' => sprintf(__('Mail delivery failed! (%s)'), $e->getMessage()));
        }

        return $this('response')->json($response);
    }

    /**
     * @return array
     */
    protected function getTimezones()
    {
        $timezones = array();

        foreach (\DateTimeZone::listIdentifiers() as $timezone) {
            $parts = explode('/', $timezone);

            if (count($parts) > 2) {
                $region = $parts[0];
                $name = $parts[1].' - '.$parts[2];
            } elseif (count($parts) > 1) {
                $region = $parts[0];
                $name = $parts[1];
            } else {
                $region = 'Other';
                $name = $parts[0];
            }

            $timezones[$region][$timezone] = str_replace('_', ' ', $name);
        }

        return $timezones;
    }
}
