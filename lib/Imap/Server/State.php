<?php


namespace PBMail\Imap\Server;


class State
{

    protected $state;


    /**
     * Create a new instance of State.
     *
     * @param mixed ...$args
     * @return State
     */
    public static function make(...$args): State
    {
        return new static(...$args);
    }

    /**
     * Create a new State.
     *
     * @param array $stateOptions The default state options.
     */
    public function __construct($stateOptions = []){
        $this->state = $stateOptions;
    }


    /**
     * Set a state option.
     *
     * @param string $state
     * @param $value
     */
    public function set(string $state, $value){
        $this->state[ $state ] = $value;
    }

    /**
     * Set a state option.
     *
     * @param string $state
     * @param $value
     * @return mixed|null
     */
    public function get(string $state, $value){
        return $this->state[ $state ] ?? null;
    }


    /**
     * Check if a state option has a specific value.
     *
     * @param string $state
     * @param $value
     * @param false $strict
     * @return bool
     */
    public function is(string $state, $value, $strict = false): bool
    {
        return $strict ? $state === $value : $state == $value;
    }


}