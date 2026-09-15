<?php

namespace SunCat\MobileDetectBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use SunCat\MobileDetectBundle\DeviceDetector\MobileDetector;

/**
 * Exercises the real detection library, without any mock.
 *
 * The rest of the suite mocks MobileDetector, so nothing would catch a change of
 * behaviour in mobiledetect/mobiledetectlib itself. These cases pin down the
 * classification the bundle relies on for a handful of representative agents.
 */
class DeviceDetectionTest extends TestCase
{
    const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    const ANDROID_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
    const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    const ANDROID_TABLET = 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const DESKTOP_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    /**
     * @param string $userAgent
     *
     * @return MobileDetector
     */
    private function detectorFor($userAgent)
    {
        $detector = new MobileDetector();
        $detector->setUserAgent($userAgent);

        return $detector;
    }

    /**
     * Given a phone user agent
     * When the device is detected
     * Then it is a mobile but not a tablet
     *
     * @test
     */
    public function phonesAreDetectedAsMobileAndNotAsTablet()
    {
        foreach ([self::IPHONE, self::ANDROID_PHONE] as $userAgent) {
            $detector = $this->detectorFor($userAgent);

            $this->assertTrue($detector->isMobile(), $userAgent);
            $this->assertFalse($detector->isTablet(), $userAgent);
        }
    }

    /**
     * Given a tablet user agent
     * When the device is detected
     * Then it is a tablet, and also a mobile since tablets are a subset of mobiles
     *
     * @test
     */
    public function tabletsAreDetectedAsTabletAndAsMobile()
    {
        foreach ([self::IPAD, self::ANDROID_TABLET] as $userAgent) {
            $detector = $this->detectorFor($userAgent);

            $this->assertTrue($detector->isTablet(), $userAgent);
            $this->assertTrue($detector->isMobile(), $userAgent);
        }
    }

    /**
     * Given a desktop user agent
     * When the device is detected
     * Then it is neither a mobile nor a tablet
     *
     * @test
     */
    public function desktopBrowsersAreNeitherMobileNorTablet()
    {
        foreach ([self::DESKTOP_CHROME, self::DESKTOP_SAFARI] as $userAgent) {
            $detector = $this->detectorFor($userAgent);

            $this->assertFalse($detector->isMobile(), $userAgent);
            $this->assertFalse($detector->isTablet(), $userAgent);
        }
    }

    /**
     * Given a user agent
     * When an is<Something>() method is called through the magic __call()
     * Then the operating system is reported
     *
     * @test
     */
    public function operatingSystemsAreReportedThroughMagicMethods()
    {
        $iphone = $this->detectorFor(self::IPHONE);
        $this->assertTrue($iphone->isIOS());
        $this->assertFalse($iphone->isAndroidOS());

        $android = $this->detectorFor(self::ANDROID_PHONE);
        $this->assertTrue($android->isAndroidOS());
        $this->assertFalse($android->isIOS());
    }

    /**
     * Given an iPhone user agent
     * When the iOS version is extracted
     * Then the version carried by the user agent is returned
     *
     * @test
     */
    public function theOperatingSystemVersionIsExtracted()
    {
        $this->assertSame('17_0', $this->detectorFor(self::IPHONE)->version('iOS'));
    }

    /**
     * Given a request carrying no User-Agent header
     * When the empty string reaches the detector
     * Then nothing is detected and no error is raised
     *
     * Since mobiledetectlib 4.0 setUserAgent() no longer accepts null, which is
     * what Request::headers->get('user-agent') returns when the header is absent.
     *
     * @test
     */
    public function anEmptyUserAgentDetectsNothing()
    {
        $detector = $this->detectorFor('');

        $this->assertFalse($detector->isMobile());
        $this->assertFalse($detector->isTablet());
    }
}
