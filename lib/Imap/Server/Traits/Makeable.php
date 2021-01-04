<?php


namespace PBMail\Imap\Server\Traits;


trait Makeable
{


    /**
     * Create an instance. Parameters are same as constructor.
     *
     * @param mixed ...$args
     * @return $this
     */
    public static function make(...$args)
    {
        return new static(...$args);
    }


}