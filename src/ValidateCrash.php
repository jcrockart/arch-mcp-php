<?php

namespace ArchMcp;

/**
 * Raised by the metadata validator when a value has a shape the checks cannot
 * read at all (for example a list where an object must be). The old Python
 * validator died with a traceback there, which failed the commit; the
 * validator now reports it as an ordinary error instead, so the result is
 * the same (the commit fails) and the message is readable.
 */
final class ValidateCrash extends \RuntimeException
{
}
