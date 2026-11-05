<?php

namespace App\Assistant;

use RuntimeException;

/** A tool saying no, with a sentence the assistant can pass on. */
class Refusal extends RuntimeException
{
}
