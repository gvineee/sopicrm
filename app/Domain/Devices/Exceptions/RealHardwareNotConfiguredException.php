<?php

namespace App\Domain\Devices\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Domain\Devices\Adapters\SupremaGSdkAdapter for every
 * operation — this codebase has no real Suprema G-SDK Device Gateway to
 * validate against, and the hard constraint ("არასდროს დაადასტურო რეალური
 * hardware ინტეგრაცია მხოლოდ simulator-ით") means this adapter must fail
 * loudly rather than silently behave like the simulator. Callers (device
 * registration/onboarding controllers) catch this and surface a clear
 * Georgian message rather than a generic 500.
 */
class RealHardwareNotConfiguredException extends RuntimeException {}
