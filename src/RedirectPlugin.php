<?php

/**
 * Craft Redirect plugin
 *
 * @author    dolphiq
 * @copyright Copyright (c) 2017 dolphiq
 * @link      https://dolphiq.nl/
 */

namespace dolphiq\redirect;

use Craft;
use craft\base\Plugin;
use craft\db\Query;
use craft\events\ElementEvent;
use craft\events\ExceptionEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\ErrorHandler;
use craft\web\View;
use dolphiq\redirect\events\RedirectEvent;
use Throwable;
use yii\web\NotFoundHttpException;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\Gql;
use craft\feedme\events\RegisterFeedMeElementsEvent;
use craft\feedme\Plugin as FeedmePlugin;
use craft\feedme\services\Elements as FeedmeElements;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\web\UrlManager;
use dolphiq\redirect\elements\FeedMeRedirect;
use dolphiq\redirect\elements\Redirect;
use dolphiq\redirect\models\Settings;
use dolphiq\redirect\services\Analytics;
use dolphiq\redirect\services\CatchAll;
use dolphiq\redirect\gql\RedirectType;
use dolphiq\redirect\services\Redirects;
use dolphiq\redirect\widgets\Latest404s;
use GraphQL\Type\Definition\Type;
use yii\base\Event;
use yii\web\Response;

class RedirectPlugin extends Plugin
{
    public static $plugin;

    /**
     * @event RedirectEvent Triggered before the catch-all logs an unmatched 404.
     */
    public const EVENT_BEFORE_CATCHALL = 'beforeCatchall';

    /**
     * Extensions left to Craft's normal 404 handling, and never logged by the
     * catch-all — a missing asset is not a candidate for a redirect.
     */
    public const FILE_EXTENSIONS = [
        'gif',
        'jpg',
        'jpeg',
        'png',
        'tiff',
        'svg',
        'ttf',
        'woff',
        'woff2',
        'otf',
        'ico',
        'js',
        'css',
    ];

    private $_redirectsService;
    private $_catchAallService;

    /**
     * Returns the Redirects service.
     *
     * @return Redirects The Redirects service
     */
    public function getRedirects()
    {
        if ($this->_redirectsService == null) {
            $this->_redirectsService = new Redirects();
        }
        /** @var WebApplication|ConsoleApplication $this */
        return $this->_redirectsService;
    }

    public function getCatchAll()
    {
        if ($this->_catchAallService == null) {
            $this->_catchAallService = new CatchAll();
        }
        /** @var WebApplication|ConsoleApplication $this */
        return $this->_catchAallService;
    }

    /**
     * Redirects the current request if a redirect matches the URI that 404'd.
     *
     * Called from craft\web\ErrorHandler's beforeHandleException. Sends the
     * response and ends the request on a match; otherwise it returns and lets
     * Craft render its own 404.
     */
    public function handleNotFound(Throwable $exception): void
    {
        // Yii wraps the original exception when the error action itself fails.
        while (!$exception instanceof NotFoundHttpException && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        if (!$exception instanceof NotFoundHttpException) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return;
        }

        // Source URLs are stored per-site and site-relative, so match on the
        // prefix-stripped path: getFullPath() would keep a site's URI prefix.
        $uri = $request->getPathInfo();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $match = $this->getRedirects()->resolveForUri($uri, $siteId);

        if ($match === null) {
            $this->_handleCatchAll($uri);
            return;
        }

        $destinationUrl = $this->getRedirects()
            ->substituteQueryParams($match['destinationUrl'], $request->getQueryParams());

        // add the site domain if the destination is not an absolute URL
        if (!str_contains($destinationUrl, '://')) {
            $destinationUrl = UrlHelper::baseUrl() . ltrim($destinationUrl, '/');
        }

        $destinationUrl = $this->getRedirects()
            ->appendQueryString($destinationUrl, $request->getQueryStringWithoutPath());

        if (!empty($match['redirectId'])) {
            $this->getRedirects()->registerHitById($match['redirectId'], $destinationUrl);
        }

        Craft::$app->getResponse()
            ->redirect($destinationUrl, (int)$match['statusCode'])
            ->send();

        Craft::$app->end();
    }

    /**
     * Logs an unmatched 404 and renders the catch-all template, if either is
     * enabled. Returns if not, leaving Craft's own 404 response in place.
     */
    private function _handleCatchAll(string $uri): void
    {
        $settings = $this->getSettings();

        if (!$settings->catchAllActive) {
            return;
        }

        // The template payload keeps the pre-5.2 shape: the raw request URI,
        // and its parts sans query string.
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $uriParts = pathinfo(current(explode('?', $requestUri)));

        if (
            isset($uriParts['extension']) &&
            $uriParts['extension'] !== '' &&
            in_array($uriParts['extension'], self::FILE_EXTENSIONS, true)
        ) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_CATCHALL, new RedirectEvent(['uri' => $uri]));

        $this->getCatchAll()->registerHitByUri($uri);

        if ($settings->catchAllTemplate === '') {
            return;
        }

        $response = Craft::$app->getResponse();
        $response->setStatusCode(404);
        $response->data = Craft::$app->getView()->renderPageTemplate($settings->catchAllTemplate, [
            'request' => [
                'requestUri' => $requestUri,
                'uriParts' => $uriParts,
            ],
        ], View::TEMPLATE_MODE_SITE);
        $response->send();

        Craft::$app->end();
    }

    private $_analyticsService;

    public function getAnalytics(): Analytics
    {
        if ($this->_analyticsService == null) {
            $this->_analyticsService = new Analytics();
        }

        return $this->_analyticsService;
    }

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    // Table schema version. Craft overrides this with extra.schemaVersion from
    // composer.json, so keep the two in step: bumping this alone does nothing.
    public string $schemaVersion = '5.2.0';

    /*
    *
    *  The Craft plugin documentation points to the EVENT_REGISTER_CP_NAV_ITEMS event to register navigation items.
    *  The getCpNavItem was found in the source and will check the user privilages already.
    *
    */
    public function getCpNavItem(): array
    {
        return [
            'url' => 'redirect',
            'label' => Craft::t('redirect', 'Site redirects'),
            'fontIcon' => 'share',
        ];
    }


    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * Return the settings response (if some one clicks on the settings/plugin icon)
     *
     */

    public function getSettingsResponse(): Response
    {
        $url = UrlHelper::cpUrl('settings/redirect/settings');
        return Craft::$app->controller->redirect($url);
    }

    /**
     * Register CP URL rules
     *
     * @param RegisterUrlRulesEvent $event
     */

    public function registerCpUrlRules(RegisterUrlRulesEvent $event)
    {
        // only register CP URLs if the user is logged in
        if (!Craft::$app->user->identity) {
            return;
        }
        $rules = [
            // register routes for the sub nav
            'redirect' => 'redirect/settings/',
            'redirect/settings' => 'redirect/settings/settings',
            'redirect/redirects' => 'redirect/settings/redirects',
            'redirect/registered-catch-all-urls' => 'redirect/settings/registered-catch-all-urls',
            'redirect/404-stats/<id:\d+>' => 'redirect/settings/catch-all-stats',
            'redirect/import-export' => 'redirect/settings/import-export',
            'redirect/export' => 'redirect/settings/export-redirects',
            'redirect/import' => 'redirect/settings/import-redirects',
            'redirect/new' => 'redirect/settings/edit-redirect',
            'redirect/<redirectId:\d+>' => 'redirect/settings/edit-redirect',

            // register routes for the settings tab

            'settings/redirect' => [
                'route' => 'redirect/settings',
                'params' => ['source' => 'CpSettings'], ],
            'settings/redirect/settings' => [
                'route' => 'redirect/settings/settings',
                'params' => ['source' => 'CpSettings'], ],
            'settings/redirect/redirects' => [
                'route' => 'redirect/settings/redirects',
                'params' => ['source' => 'CpSettings'], ],
            'settings/redirect/registered-catch-all-urls' => [
                'route' => 'redirect/settings/registered-catch-all-urls',
                'params' => ['source' => 'CpSettings'], ],
            'settings/redirect/import-export' => [
                'route' => 'redirect/settings/import-export',
                'params' => ['source' => 'CpSettings'], ],
            'settings/redirect/new' => [
                'route' => 'redirect/settings/edit-redirect',
                'params' => ['source' => 'CpSettings'], ],
            'settings/redirect/<redirectId:\d+>' => [
                'route' => 'redirect/settings/edit-redirect',
                'params' => ['source' => 'CpSettings'], ],
        ];
        $event->rules = array_merge($event->rules, $rules);
    }

    /**
     * Registers our custom feed import logic if feed-me is enabled. Also note, we're checking for craft\feedme
     */
    private function registerFeedMeElement()
    {
        if (Craft::$app->plugins->isPluginEnabled('feed-me') && class_exists(FeedmePlugin::class)) {
            Event::on(FeedmeElements::class, FeedmeElements::EVENT_REGISTER_FEED_ME_ELEMENTS, function(RegisterFeedMeElementsEvent $e) {
                $e->elements[] = FeedMeRedirect::class;
            });
        }
    }


    /**
     * Builds the Yii URL-rule key for a redirect source URL: drops any `#`
     * fragment and wraps a purely numeric source in slashes so it routes as a
     * path segment (e.g. `12` -> `/12/`).
     */
    public static function ruleKeyForSourceUrl(string $sourceUrl): string
    {
        if (strpos($sourceUrl, '#') !== false) {
            $sourceUrl = current(explode('#', $sourceUrl));
        }

        if (is_numeric($sourceUrl)) {
            $sourceUrl = '/' . $sourceUrl . '/';
        }

        return $sourceUrl;
    }

    public function init()
    {
        parent::init();

        self::$plugin = $this;

        // only register CP URLs if the user is logged in
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, [$this, 'registerCpUrlRules']);

        // Register FeedMe ElementType
        $this->registerFeedMeElement();

        // Register the "Latest 404s" dashboard widget
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Latest404s::class;
        });

        // Register the `redirects` GraphQL query
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries['redirects'] = [
                'type' => Type::listOf(RedirectType::getType()),
                'args' => ['siteId' => Type::int()],
                'resolve' => static function($source, array $arguments) {
                    $siteId = $arguments['siteId'] ?? Craft::$app->getSites()->getPrimarySite()->id;
                    return RedirectPlugin::$plugin->getRedirects()->getRedirectDataForSite((int)$siteId);
                },
            ];
        });

        $settings = RedirectPlugin::$plugin->getSettings();
        if ($settings->redirectsActive) {
            // Resolve redirects from Craft's 404 handler rather than from a catch-all
            // URL rule. craft\web\UrlManager::_getRequestRoute() evaluates URL rules
            // before .well-known routes and before template routes, so a catch-all rule
            // pre-empts routing that Craft should own: templates stop resolving, their
            // route params never bind (breaking set-password and email verification),
            // and .well-known requests are swallowed. Hooking the 404 instead means
            // Craft routes everything natively and only a request that would genuinely
            // 404 reaches us.
            Event::on(ErrorHandler::class, ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION, function(ExceptionEvent $event) {
                $this->handleNotFound($event->exception);
            });
        }

        // Automatically create a 301 when an element's URI changes.
        if ($settings->autoCreateRedirectOnUriChange) {
            $oldUris = [];

            Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function(ElementEvent $event) use (&$oldUris) {
                $element = $event->element;
                if ($event->isNew || $element instanceof Redirect || !$element->id || $element->getIsDraft() || $element->getIsRevision()) {
                    return;
                }

                $oldUri = (new Query())
                    ->select(['uri'])
                    ->from('{{%elements_sites}}')
                    ->where(['elementId' => $element->id, 'siteId' => $element->siteId])
                    ->scalar();

                if ($oldUri) {
                    $oldUris["{$element->id}-{$element->siteId}"] = $oldUri;
                }
            });

            Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) use (&$oldUris) {
                $element = $event->element;
                if ($element instanceof Redirect) {
                    return;
                }

                $key = "{$element->id}-{$element->siteId}";
                $oldUri = $oldUris[$key] ?? null;
                $newUri = $element->uri;
                unset($oldUris[$key]);

                if ($oldUri && $newUri && $oldUri !== $newUri) {
                    self::$plugin->getRedirects()->createUriChangeRedirect($oldUri, $newUri, (int)$element->siteId);
                }
            });
        }

        // Prune old 404 analytics during Craft's garbage collection.
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $settings = RedirectPlugin::$plugin->getSettings();
            if ($settings->analyticsEnabled) {
                self::$plugin->getAnalytics()->prune((int)$settings->analyticsRetentionDays);
            }
        });

        Craft::info('dolphiq/redirect Plugin plugin loaded', __METHOD__);
    }
}
