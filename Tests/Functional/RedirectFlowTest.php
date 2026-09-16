<?php

namespace SunCat\MobileDetectBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use SunCat\MobileDetectBundle\DeviceDetector\MobileDetector;
use SunCat\MobileDetectBundle\EventListener\RequestResponseListener;
use SunCat\MobileDetectBundle\Helper\DeviceView;
use SunCat\MobileDetectBundle\Helper\RedirectResponseWithCookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

/**
 * Drives the listener end to end: real request, real router, real device view and
 * real detection library. Only the HTTP kernel is stubbed, because an event needs
 * one and the listener never calls it.
 */
class RedirectFlowTest extends TestCase
{
    /**
     * @var HttpKernelInterface
     */
    private $kernel;

    public function setUp(): void
    {
        $this->kernel = new class() implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }

    /**
     * @return Router
     */
    private function router()
    {
        return new Router(new ClosureLoader(), function () {
            $routes = new RouteCollection();
            $routes->add('home', new Route('/some/path'));

            return $routes;
        });
    }

    /**
     * @param bool $mobileRedirect
     * @param bool $detectTabletAsMobile
     *
     * @return array
     */
    private function config($mobileRedirect = true, $detectTabletAsMobile = false)
    {
        return [
            'mobile' => [
                'is_enabled' => $mobileRedirect,
                'host' => 'http://m.example.com',
                'status_code' => 302,
                'action' => RequestResponseListener::REDIRECT,
            ],
            'tablet' => [
                'is_enabled' => false,
                'host' => null,
                'status_code' => 302,
                'action' => RequestResponseListener::REDIRECT,
            ],
            'full' => [
                'is_enabled' => false,
                'host' => null,
                'status_code' => 302,
                'action' => RequestResponseListener::REDIRECT,
            ],
            'detect_tablet_as_mobile' => $detectTabletAsMobile,
        ];
    }

    /**
     * Builds the listener and runs the kernel.request phase for the given agent.
     *
     * @param string $userAgent
     * @param string $uri
     * @param array  $config
     *
     * @return array [RequestResponseListener, DeviceView, RequestEvent]
     */
    private function handleRequest($userAgent, $uri = 'http://example.com/some/path?a=b', ?array $config = null)
    {
        $request = Request::create($uri);
        $request->headers->set('user-agent', $userAgent);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $deviceView = new DeviceView($requestStack);
        $listener = new RequestResponseListener(
            new MobileDetector(),
            $deviceView,
            $this->router(),
            null === $config ? $this->config() : $config
        );

        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->handleRequest($event);

        return [$listener, $deviceView, $event];
    }

    /**
     * Given a phone and an enabled mobile redirection
     * When the request is handled
     * Then the visitor is redirected to the mobile host, keeping path and query
     *
     * @test
     */
    public function aPhoneIsRedirectedToTheMobileHost()
    {
        list(, , $event) = $this->handleRequest(DeviceDetectionTest::IPHONE);

        $response = $event->getResponse();

        $this->assertInstanceOf(RedirectResponseWithCookie::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'http://m.example.com/some/path?a=b&device_view=mobile',
            $response->getTargetUrl()
        );
    }

    /**
     * Given a phone redirected to the mobile host
     * When the response is inspected
     * Then it carries the device view cookie, so the next request skips detection
     *
     * @test
     */
    public function theRedirectCarriesTheDeviceViewCookie()
    {
        list(, , $event) = $this->handleRequest(DeviceDetectionTest::IPHONE);

        $cookies = $event->getResponse()->headers->getCookies();

        $this->assertCount(1, $cookies);
        $this->assertSame(DeviceView::COOKIE_KEY_DEFAULT, $cookies[0]->getName());
        $this->assertSame(DeviceView::VIEW_MOBILE, $cookies[0]->getValue());
    }

    /**
     * Given a desktop browser
     * When the request is handled
     * Then no redirection happens and the full view is retained
     *
     * @test
     */
    public function aDesktopBrowserIsNotRedirected()
    {
        list(, $deviceView, $event) = $this->handleRequest(DeviceDetectionTest::DESKTOP_CHROME);

        $this->assertNull($event->getResponse());
        $this->assertSame(DeviceView::VIEW_FULL, $deviceView->getViewType());
    }

    /**
     * Given a desktop browser that was not redirected
     * When the response phase runs
     * Then the device view cookie is appended to the response
     *
     * @test
     */
    public function theFullViewCookieIsAppendedOnTheResponse()
    {
        list($listener, $deviceView) = $this->handleRequest(DeviceDetectionTest::DESKTOP_CHROME);

        $this->assertTrue($listener->needsResponseModification());

        $request = Request::create('http://example.com/some/path');
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response());
        $listener->handleResponse($event);

        $cookies = $event->getResponse()->headers->getCookies();

        $this->assertCount(1, $cookies);
        $this->assertSame(DeviceView::VIEW_FULL, $cookies[0]->getValue());
    }

    /**
     * Given a tablet and detect_tablet_as_mobile turned off
     * When the request is handled
     * Then the tablet view is selected and the disabled tablet redirection applies
     *
     * @test
     */
    public function aTabletKeepsItsOwnViewByDefault()
    {
        list(, $deviceView, $event) = $this->handleRequest(DeviceDetectionTest::IPAD);

        $this->assertSame(DeviceView::VIEW_TABLET, $deviceView->getViewType());
        $this->assertNull($event->getResponse());
    }

    /**
     * Given a tablet and detect_tablet_as_mobile turned on
     * When the request is handled
     * Then the tablet is treated as a phone and follows the mobile redirection
     *
     * @test
     */
    public function aTabletIsTreatedAsMobileWhenConfigured()
    {
        list(, $deviceView, $event) = $this->handleRequest(
            DeviceDetectionTest::IPAD,
            'http://example.com/some/path?a=b',
            $this->config(true, true)
        );

        $this->assertSame(DeviceView::VIEW_MOBILE, $deviceView->getViewType());
        $this->assertInstanceOf(RedirectResponseWithCookie::class, $event->getResponse());
    }

    /**
     * Given a desktop browser explicitly asking for the mobile view
     * When the request carries the switch parameter
     * Then the requested view wins over the detection
     *
     * @test
     */
    public function theSwitchParameterOverridesTheDetection()
    {
        list(, $deviceView, $event) = $this->handleRequest(
            DeviceDetectionTest::DESKTOP_CHROME,
            'http://example.com/some/path?device_view=mobile'
        );

        $this->assertSame(DeviceView::VIEW_MOBILE, $deviceView->getViewType());

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponseWithCookie::class, $response);
        $this->assertStringStartsWith('http://m.example.com', $response->getTargetUrl());
    }

    /**
     * Given a request without any User-Agent header
     * When the request is handled
     * Then detection falls back to the full view instead of failing
     *
     * @test
     */
    public function aRequestWithoutUserAgentFallsBackToTheFullView()
    {
        $request = Request::create('http://example.com/some/path');

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $deviceView = new DeviceView($requestStack);
        $listener = new RequestResponseListener(
            new MobileDetector(),
            $deviceView,
            $this->router(),
            $this->config()
        );

        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->handleRequest($event);

        $this->assertNull($event->getResponse());
        $this->assertSame(DeviceView::VIEW_FULL, $deviceView->getViewType());
    }

    /**
     * Given a custom switch_param
     * When the request carries it
     * Then the view type is still unresolved while the switch param is seen
     *
     * DeviceView reads the query with SWITCH_PARAM_DEFAULT in its constructor,
     * whereas the container calls setSwitchParam() afterwards. getViewType()
     * therefore returns null while hasSwitchParam() is true, and mustRedirect()
     * receives that null. PHP 8.5 deprecates null as an array offset, including
     * inside isset(), so the listener has to cast it.
     *
     * @test
     */
    public function aCustomSwitchParamLeavesTheViewTypeUnresolved()
    {
        $request = Request::create('http://example.com/some/path?custom_switch=mobile');
        $request->headers->set('user-agent', DeviceDetectionTest::IPHONE);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $deviceView = new DeviceView($requestStack);
        $deviceView->setSwitchParam('custom_switch');

        $this->assertTrue($deviceView->hasSwitchParam());
        $this->assertNull($deviceView->getViewType());

        $listener = new RequestResponseListener(
            new MobileDetector(),
            $deviceView,
            $this->router(),
            $this->config()
        );

        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->handleRequest($event);

        // No redirection target matches an unresolved view, so the visitor stays
        // on the current host and only receives the cookie.
        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponseWithCookie::class, $response);
        $this->assertSame('http://example.com/some/path', $response->getTargetUrl());
    }

    /**
     * Given a redirect configuration built by hand, without the 'action' key
     * When the routing option is resolved
     * Then no redirection happens and no warning is raised
     *
     * The DI configuration always provides an 'action', but the listener accepts
     * any array. A missing key used to be a silent notice on PHP 7 and became a
     * warning on PHP 8.
     *
     * @test
     */
    public function aConfigurationWithoutAnActionDoesNotRedirect()
    {
        $config = $this->config();
        unset($config['mobile']['action']);

        list($listener, $deviceView, $event) = $this->handleRequest(
            DeviceDetectionTest::IPHONE,
            'http://example.com/some/path?a=b',
            $config
        );

        $this->assertSame(DeviceView::VIEW_MOBILE, $deviceView->getViewType());
        $this->assertNull($event->getResponse());
        $this->assertFalse($listener->needsResponseModification());
    }
}
