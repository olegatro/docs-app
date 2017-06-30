<?php

namespace Docs\Controllers;

use Phalcon\Config;
use Phalcon\Mvc\View\Simple;
use Phalcon\Cache\BackendInterface;
use Phalcon\Http\ResponseInterface;
use Phalcon\Mvc\Controller as PhController;
use function Docs\Functions\config;
use function Docs\Functions\app_path;
use function Docs\Functions\environment;

/**
 * Class DocsController
 *
 * @package Docs\Controllers
 *
 * @property Config           $config
 * @property BackendInterface $cacheData
 * @property \ParsedownExtra  $parsedown
 * @property Simple           $viewSimple
 */
class DocsController extends PhController
{
    /**
     * Performs necessary redirection
     *
     * @return \Phalcon\Http\ResponseInterface
     */
    public function redirectAction(): ResponseInterface
    {
        return $this->response->redirect($this->getVersion('/en/'));
    }

    /**
     * Returns the current version string with its prefix if applicable
     *
     * @param  string $stub
     * @return string
     */
    private function getVersion(string $stub = ''): string
    {
        return $stub . config('app.version', '9999');
    }

    /**
     * @param null|string $language
     * @param null|string $version
     * @param string      $page
     *
     * @return \Phalcon\Http\ResponseInterface
     */
    public function mainAction(string $language = null, string $version = null, string $page = ''): ResponseInterface
    {
        if (empty($language)) {
            return $this->response->redirect($this->getVersion('/en/'));
        }

        $language = $this->getLanguage($language);

        if (empty($version)) {
            return $this->response->redirect($this->getVersion('/' . $language . '/'));
        }

        if (strtolower($version) === 'latest') {
            $version = $this->getVersion();
        }

        if (empty($page)) {
            $page = 'introduction';
        }

        $slug     = str_replace(['/' . $language, '/' . $version], ['', ''], $this->request->getURI());
        $contents = $this->viewSimple->render(
            'index/index',
            [
                'language' => $language,
                'version'  => $version,
                'sidebar'  => $this->getDocument($language, $version, 'sidebar'),
                'article'  => $this->getDocument($language, $version, $page),
                'menu'     => $this->getDocument($language, $version, $page . '-menu'),
                'slug'     => $slug,
            ]
        );
        $this->response->setContent($contents);

        return $this->response;
    }

    /**
     * Check the available languages and return either that or 'en'
     *
     * @param string $language
     *
     * @return string
     */
    private function getLanguage(string $language): string
    {
        $languages = config('languages');

        if (!isset($languages[$language])) {
            return 'en';
        }

        return $language;
    }

    /**
     * @param string $language
     * @param string $version
     * @param string $fileName
     *
     * @return string
     */
    private function getDocument($language, $version, $fileName): string
    {
        $key = sprintf('%s.%s.%s.cache', $fileName, $version, $language);

        if (environment('production') && true === $this->cacheData->exists($key)) {
            return $this->cacheData->get($key);
        }

        $pageName    = app_path(sprintf('docs/%s/%s/%s.md', $version, $language, $fileName));
        $apiFileName = app_path(sprintf('docs/%s/%s/api/%s.md', $version, $language, $fileName));

        $data = '';
        if (file_exists($pageName)) {
            $data = file_get_contents($pageName);
        } elseif (file_exists($apiFileName)) {
            $data = file_get_contents($apiFileName);
        }

        if (!empty($data)) {
            $namespaces = $this->getNamespaces();
            $from       = array_keys($namespaces);
            $to         = array_values($namespaces);

            /**
             * API links
             */
            $data = str_replace($from, $to, $data);

            /**
             * Language and version
             */
            $data = str_replace(
                [
                    '[[language]]',
                    '[[version]]',
                    '0#', '1#', '2#', '3#', '4#',
                    '5#', '6#', '7#', '8#', '9#',
                    '0`', '1`', '2`', '3`', '4`',
                    '5`', '6`', '7`', '8`', '9`',
                ],
                [
                    $language,
                    $this->getVersion(),
                    '#', '#', '#', '#', '#',
                    '#', '#', '#', '#', '#',
                    '`', '`', '`', '`', '`',
                    '`', '`', '`', '`', '`',
                ],
                $data
            );
            $data = $this->parsedown->text($data);
            $this->cacheData->save($key, $data);
        }

        return $data;
    }

    /**
     * Gets all the namespaces so that API URLs are generated properly
     *
     * @return array
     */
    private function getNamespaces(): array
    {
        $key = 'namespaces.cache';
        if (environment('production') && true === $this->cacheData->exists($key)) {
            return $this->cacheData->get($key);
        }

        $namespaces = [];
        $template   = '[%s](/[[language]]/[[version]]/api/%s)';

        $data = get_declared_classes();
        foreach ($data as $name) {
            if (substr($name, 0, 8) === 'Phalcon\\') {
                $apiName               = str_replace('\\', '_', $name);
                $namespaces["`$name`"] = sprintf($template, $name, $apiName);
            }
        }

        $data = get_declared_interfaces();
        foreach ($data as $name) {
            if (substr($name, 0, 8) === 'Phalcon\\') {
                $apiName               = str_replace('\\', '_', $name);
                $namespaces["`$name`"] = sprintf($template, $name, $apiName);
            }
        }

        $this->cacheData->save($key, $namespaces);

        return $namespaces;
    }
}
