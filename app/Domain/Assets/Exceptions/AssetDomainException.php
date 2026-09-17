<?php

namespace App\Domain\Assets\Exceptions;

use RuntimeException;

/**
 * Base class for every Assets/Tools & Custody domain-rule violation (spec
 * section 9). Controllers catch this family and translate it into a 409/422
 * JSON or Inertia-flash error with a Georgian message, never a raw 500.
 */
abstract class AssetDomainException extends RuntimeException {}
