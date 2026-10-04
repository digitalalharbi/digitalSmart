<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller;

/**
 * Base controller.
 *
 * Intentionally empty: controllers translate HTTP to a use-case call and back.
 * Shared behaviour belongs in middleware, form requests or the Application layer,
 * never in a fat base class.
 */
abstract class Controller {}
