<?php

namespace App\Exceptions;

use DomainException;

/** The install's plan does not allow adding another client or staff user. A 422 in the API, a flash message on the web. */
class PlanLimitReached extends DomainException {}
