<?php

namespace SunCat\MobileDetectBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use SunCat\MobileDetectBundle\DeviceDetector\MobileDetector;
use SunCat\MobileDetectBundle\Helper\DeviceView;
use SunCat\MobileDetectBundle\Twig\Extension\MobileDetectExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Exercises the Twig functions against the real detection library and a real
 * request, so that the template-facing contract is covered without mocks.
 */
class TwigExtensionTest extends TestCase
{
    /**
     * @param string $userAgent
     * @param string $uri
     *
     * @return MobileDetectExtension
     */
    private function extensionFor($userAgent, $uri = 'http://example.com/some/path?a=b')
    {
        $request = Request::create($uri);
        $request->headers->set('user-agent', $userAgent);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $detector = new MobileDetector();
        $detector->setUserAgent($userAgent);

        $extension = new MobileDetectExtension(
            $detector,
            new DeviceView($requestStack),
            ['full' => ['is_enabled' => true, 'host' => 'http://example.com']]
        );
        $extension->setRequestByRequestStack($requestStack);

        return $extension;
    }

    /**
     * Given a phone user agent
     * When the is_mobile and is_tablet functions are called
     * Then they reflect the detection
     *
     * @test
     */
    public function isMobileAndIsTabletReflectTheUserAgent()
    {
        $phone = $this->extensionFor(DeviceDetectionTest::IPHONE);
        $this->assertTrue($phone->isMobile());
        $this->assertFalse($phone->isTablet());

        $tablet = $this->extensionFor(DeviceDetectionTest::IPAD);
        $this->assertTrue($tablet->isTablet());

        $desktop = $this->extensionFor(DeviceDetectionTest::DESKTOP_CHROME);
        $this->assertFalse($desktop->isMobile());
        $this->assertFalse($desktop->isTablet());
    }

    /**
     * Given a phone user agent
     * When the is_ios and is_android_os functions are called
     * Then the operating system is reported
     *
     * @test
     */
    public function theOperatingSystemIsReported()
    {
        $iphone = $this->extensionFor(DeviceDetectionTest::IPHONE);
        $this->assertTrue($iphone->isIOS());
        $this->assertFalse($iphone->isAndroidOS());

        $android = $this->extensionFor(DeviceDetectionTest::ANDROID_PHONE);
        $this->assertTrue($android->isAndroidOS());
        $this->assertFalse($android->isIOS());
    }

    /**
     * Given a device name
     * When the is_device function is called
     * Then the underlying magic method of the detection library answers
     *
     * @test
     */
    public function isDeviceResolvesTheMagicMethod()
    {
        $extension = $this->extensionFor(DeviceDetectionTest::IPHONE);

        $this->assertTrue($extension->isDevice('iPhone'));
        $this->assertFalse($extension->isDevice('BlackBerry'));
    }

    /**
     * Given an iPhone user agent
     * When the device_version function is called
     * Then the version carried by the user agent is returned
     *
     * @test
     */
    public function deviceVersionReturnsTheOperatingSystemVersion()
    {
        $extension = $this->extensionFor(DeviceDetectionTest::IPHONE);

        $this->assertSame('17_0', $extension->deviceVersion('iOS'));
    }

    /**
     * Given a configured full view host
     * When the full_view_url function is called
     * Then it points at the full host, keeping the current path and query
     *
     * @test
     */
    public function fullViewUrlKeepsThePathAndTheQuery()
    {
        $extension = $this->extensionFor(DeviceDetectionTest::IPHONE);

        $this->assertSame('http://example.com/some/path?a=b', $extension->fullViewUrl());
        $this->assertSame('http://example.com', $extension->fullViewUrl(false));
    }

    /**
     * Given a request without the switch parameter nor the cookie
     * When the view type predicates are called
     * Then the visitor is in none of the explicit views yet
     *
     * @test
     */
    public function theViewPredicatesFollowTheDeviceView()
    {
        $extension = $this->extensionFor(DeviceDetectionTest::IPHONE);

        $this->assertFalse($extension->isMobileView());
        $this->assertFalse($extension->isTabletView());
        $this->assertFalse($extension->isFullView());
        $this->assertFalse($extension->isNotMobileView());
    }

    /**
     * Given a request carrying the switch parameter
     * When the view type predicates are called
     * Then the requested view is reported
     *
     * @test
     */
    public function theRequestedViewIsReported()
    {
        $extension = $this->extensionFor(
            DeviceDetectionTest::IPHONE,
            'http://example.com/some/path?device_view=mobile'
        );

        $this->assertTrue($extension->isMobileView());
        $this->assertFalse($extension->isFullView());
    }
}
