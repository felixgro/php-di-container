<?php

namespace FGDI\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use Exception;

class NotFoundException extends Exception implements NotFoundExceptionInterface
{
    //
}